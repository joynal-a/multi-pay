<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Exceptions\InvalidPaymentPayloadException;
use Abedin\MultiPay\Models\PaymentSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * StroWallet Payment Collection (hosted checkout, Nigeria).
 *
 * The customer pays on StroWallet's page (auto bank transfer or StroWallet
 * account), is sent back to success_url / cancel_url, and StroWallet hits
 * callback_url?reference=...&status=... when the payment settles. Bank
 * transfers can settle after the redirect, so a "pending" result on the
 * success route is normal — the callback confirms it later.
 *
 * Only NGN and USD are accepted. Get the public key from
 * https://strowallet.com/user/api-key (there is no separate sandbox).
 */
class StrowalletGateway extends BaseGateway
{
    protected const CHECKOUT_URL = 'https://strowallet.com/pay';
    protected const STATUS_URL = 'https://strowallet.com/api/checkout/status';
    protected const CURRENCIES = ['NGN', 'USD'];

    public function needyConfig(): array
    {
        return [
            'public_key',
        ];
    }

    protected function requestPayment(PaymentRequestData $payment): PaymentResponseData
    {
        $currency = strtoupper($payment->currency);

        if (!in_array($currency, self::CURRENCIES, true)) {
            throw new InvalidPaymentPayloadException(
                "StroWallet only accepts NGN or USD, '{$currency}' given."
            );
        }

        $payload = [
            'public_key' => $this->config['public_key'],
            'amount' => number_format($payment->amount, 2, '.', ''),
            'currency' => $currency,
            'description' => (string) ($payment->meta['description']
                ?? $payment->meta['title']
                ?? ('Payment for order ' . $payment->orderId)),
            'customer_name' => $payment->customer->name ?: 'Customer',
            'customer_email' => $payment->customer->email ?: 'customer@example.com',
            'callback_url' => $this->callbackUrl(),
            'success_url' => $this->successUrl(),
            'cancel_url' => $this->cancelUrl(),
        ];

        try {
            $response = Http::acceptJson()->asJson()->post(self::CHECKOUT_URL, $payload);

            $json = (array) $response->json();
            $checkoutUrl = data_get($json, 'data.checkout_url');

            if (!$response->successful() || ($json['status'] ?? false) !== true || !$checkoutUrl) {
                throw new \Exception(json_encode($json) ?: $response->body());
            }

            return new PaymentResponseData(
                true,
                $this->gatewayName,
                (string) data_get($json, 'data.reference'),
                (string) $checkoutUrl,
                'pending',
                false,
                'StroWallet checkout URL generated successfully.',
                $json
            );
        } catch (\Throwable $e) {
            throw new \Exception('StroWallet Payment Initialization Failed: ' . $e->getMessage());
        }
    }

    protected function verifyPayment(PaymentSession $session, Request $request): PaymentResponseData
    {
        // Only the reference stored at initiation is trusted — the
        // ?reference=&status= StroWallet appends to the callback is ignored.
        $reference = (string) ($session->payment_id ?: data_get($session->raw_response, 'data.reference', ''));

        if ($reference === '') {
            return $this->unverified($session, 'StroWallet session has no checkout reference to look up.');
        }

        $response = Http::acceptJson()->get(self::STATUS_URL, [
            'public_key' => $this->config['public_key'],
            'reference' => $reference,
        ]);

        // A pending payment comes back as HTTP 400 with a normal body, so
        // read the body before looking at the status code.
        $json = (array) $response->json();
        $data = (array) ($json['data'] ?? []);

        if (($json['status'] ?? false) !== true) {
            return $this->unverified(
                $session,
                'StroWallet lookup failed: ' . ($json['message'] ?? ('HTTP ' . $response->status())),
                $json,
                'pending'
            );
        }

        $status = strtolower((string) ($data['payment_status'] ?? ''));
        $referenceOk = (string) ($data['reference'] ?? '') === $reference;
        $amountOk = (float) ($data['amount'] ?? 0) >= (float) $session->amount;
        $currencyOk = strtoupper((string) ($data['currency'] ?? '')) === strtoupper((string) $session->currency);

        if ($status === 'paid' && $response->successful()) {
            if ($referenceOk && $amountOk && $currencyOk) {
                return $this->verified($session, $reference, $json, 'StroWallet payment verified.');
            }

            return $this->unverified($session, 'StroWallet reference/amount/currency does not match the session.', $json);
        }

        $mapped = match ($status) {
            'failed', 'declined' => 'failed',
            'cancelled', 'canceled' => 'cancelled',
            'expired' => 'expired',
            default => 'pending',
        };

        return $this->unverified(
            $session,
            "StroWallet reports payment status '" . ($status ?: 'unknown') . "'.",
            $json,
            $mapped
        );
    }
}
