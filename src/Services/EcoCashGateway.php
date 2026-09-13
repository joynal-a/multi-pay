<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Exceptions\InvalidPaymentPayloadException;
use Abedin\MultiPay\Models\PaymentSession;
use Abedin\MultiPay\Support\AppRoutes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * EcoCash (Zimbabwe) Open API — instant C2B mobile-money payment.
 *
 * There is no hosted checkout: the API pushes a USSD/PIN prompt to the
 * customer's phone. The payment URL is the package's waiting page, which
 * polls the transaction lookup and forwards to the app's success/failure
 * route once EcoCash reports a final status.
 *
 * Requires the customer's EcoCash number: pass customer.phone
 * (e.g. 0771234567 or 263771234567).
 *
 * mode: sandbox | live
 */
class EcoCashGateway extends BaseGateway
{
    protected const BASE_URL = 'https://developers.ecocash.co.zw/api/ecocash_pay';

    public function needyConfig(): array
    {
        return [
            'api_key',
            'mode',
        ];
    }

    protected function requestPayment(PaymentRequestData $payment): PaymentResponseData
    {
        $msisdn = $this->normalizeMsisdn((string) ($payment->customer->phone ?? $payment->meta['phone'] ?? ''));

        // The session UUID doubles as EcoCash's sourceReference (must be a UUID).
        $reference = $this->sessionIdentifier();

        $body = [
            'customerMsisdn' => $msisdn,
            'amount' => round($payment->amount, 2),
            'reason' => (string) ($payment->meta['title'] ?? ('Order ' . $payment->orderId)),
            'currency' => $this->currencyCode($payment->currency ?: 'USD'),
            'sourceReference' => $reference,
        ];

        $response = Http::withHeaders(['X-API-KEY' => $this->config['api_key']])
            ->acceptJson()
            ->post($this->endpoint('/api/v2/payment/instant/c2b'), $body);

        if (!$response->successful()) {
            throw new \Exception(
                'EcoCash Payment Initialization Failed: ' . ($response->json('message') ?? $response->body())
            );
        }

        return new PaymentResponseData(
            true,
            $this->gatewayName,
            $reference,
            AppRoutes::internalUrl('mobile-money.checkout', $reference),
            'pending',
            false,
            'EcoCash payment prompt sent to the customer\'s phone.',
            [
                'customer_msisdn' => $msisdn,
                'source_reference' => $reference,
                'response' => (array) $response->json(),
            ]
        );
    }

    protected function verifyPayment(PaymentSession $session, Request $request): PaymentResponseData
    {
        // Always ask EcoCash directly — nothing in the request is trusted.
        $msisdn = (string) data_get($session->raw_response, 'customer_msisdn', '');
        $reference = (string) data_get($session->raw_response, 'source_reference', $session->routeIdentifier());

        if ($msisdn === '') {
            return $this->unverified($session, 'EcoCash session has no customer number to look up.', []);
        }

        $response = Http::withHeaders(['X-API-KEY' => $this->config['api_key']])
            ->acceptJson()
            ->post($this->endpoint('/api/v1/transaction/c2b/status'), [
                'sourceMobileNumber' => $msisdn,
                'sourceReference' => $reference,
            ]);

        $data = (array) $response->json();

        if (!$response->successful()) {
            return $this->unverified(
                $session,
                'EcoCash lookup failed: ' . ($data['message'] ?? ('HTTP ' . $response->status())),
                $data,
                'pending'
            );
        }

        $status = strtoupper((string) ($data['status'] ?? ''));
        $amountOk = (float) data_get($data, 'amount.amount', 0) >= (float) $session->amount;
        $currency = (string) data_get($data, 'amount.currency', '');
        $currencyOk = $currency === ''
            || strtoupper($currency) === strtoupper($this->currencyCode((string) $session->currency));

        if (in_array($status, ['SUCCESS', 'COMPLETED'], true)) {
            if ($amountOk && $currencyOk) {
                return $this->verified(
                    $session,
                    (string) ($data['ecocashReference'] ?? $reference),
                    $data,
                    'EcoCash payment verified.'
                );
            }

            return $this->unverified($session, 'EcoCash amount/currency does not match the session.', $data);
        }

        $mapped = match ($status) {
            'FAILED', 'DECLINED', 'REJECTED', 'EXPIRED' => 'failed',
            'CANCELLED', 'CANCELED' => 'cancelled',
            default => 'pending',
        };

        return $this->unverified($session, "EcoCash reports transaction status '{$status}'.", $data, $mapped);
    }

    protected function endpoint(string $path): string
    {
        $mode = strtolower((string) $this->config['mode']) === 'live' ? 'live' : 'sandbox';

        return self::BASE_URL . $path . '/' . $mode;
    }

    /**
     * EcoCash expects 263XXXXXXXXX.
     */
    protected function normalizeMsisdn(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '00263')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $digits = '263' . substr($digits, 1);
        } elseif (strlen($digits) === 9) {
            $digits = '263' . $digits;
        }

        if (!preg_match('/^2637\d{8}$/', $digits)) {
            throw new InvalidPaymentPayloadException(
                'EcoCash needs a valid Zimbabwe mobile number in customer.phone (e.g. 0771234567).'
            );
        }

        return $digits;
    }

    /**
     * EcoCash spells the Zimbabwe Gold currency "ZiG".
     */
    protected function currencyCode(string $currency): string
    {
        return in_array(strtoupper($currency), ['ZIG', 'ZWG'], true) ? 'ZiG' : strtoupper($currency);
    }
}
