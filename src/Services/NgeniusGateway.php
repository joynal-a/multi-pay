<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Models\PaymentSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Network International N-Genius hosted payment page.
 * base_url: https://api-gateway.sandbox.ngenius-payments.com (sandbox)
 *           https://api-gateway.ngenius-payments.com (live)
 */
class NgeniusGateway extends BaseGateway
{
    protected const PAID_STATES = ['PURCHASED', 'CAPTURED'];

    public function needyConfig(): array
    {
        return [
            'api_key',
            'outlet_ref',
            'base_url',
        ];
    }

    protected function requestPayment(PaymentRequestData $payment): PaymentResponseData
    {
        $currency = strtoupper($payment->currency ?: 'AED');

        $payload = [
            'action' => 'SALE',
            'amount' => [
                'currencyCode' => $currency,
                'value' => $this->minorAmount($payment->amount, $currency),
            ],
            'merchantOrderReference' => $payment->orderId,
            'emailAddress' => $payment->customer->email ?? 'customer@example.com',
            'merchantAttributes' => [
                'redirectUrl' => $this->callbackUrl(),
                'skipConfirmationPage' => true,
            ],
        ];

        try {
            $response = Http::withToken($this->accessToken())
                ->withHeaders([
                    'Content-Type' => 'application/vnd.ni-payment.v2+json',
                    'Accept' => 'application/vnd.ni-payment.v2+json',
                ])
                ->post(
                    rtrim($this->config['base_url'], '/') . '/transactions/outlets/'
                        . urlencode($this->config['outlet_ref']) . '/orders',
                    $payload
                );

            $json = (array) $response->json();

            if (!$response->successful()) {
                throw new \Exception(json_encode($json) ?: $response->body());
            }

            return new PaymentResponseData(
                true,
                $this->gatewayName,
                $json['reference'] ?? null,
                data_get($json, '_links.payment.href'),
                'pending',
                false,
                'N-Genius payment page generated successfully.',
                $json
            );
        } catch (\Throwable $e) {
            throw new \Exception('N-Genius Payment Initialization Failed: ' . $e->getMessage());
        }
    }

    protected function verifyPayment(PaymentSession $session, Request $request): PaymentResponseData
    {
        // Fetch the order stored at initiation and inspect its payment state.
        $response = Http::withToken($this->accessToken())
            ->withHeaders(['Accept' => 'application/vnd.ni-payment.v2+json'])
            ->get(
                rtrim($this->config['base_url'], '/') . '/transactions/outlets/'
                    . urlencode($this->config['outlet_ref']) . '/orders/' . urlencode((string) $session->payment_id)
            );

        $json = (array) $response->json();
        $payments = (array) data_get($json, '_embedded.payment', []);

        foreach ($payments as $paymentRow) {
            $state = strtoupper((string) ($paymentRow['state'] ?? ''));

            if (in_array($state, self::PAID_STATES, true)) {
                return $this->verified($session, $session->payment_id, $json, 'N-Genius payment verified.');
            }
        }

        $latestState = (string) data_get($payments, '0.state', '');

        return $this->unverified(
            $session,
            $payments === []
                ? 'N-Genius has no payment attempt for this order yet.'
                : "N-Genius reports payment state '{$latestState}'.",
            $json,
            in_array(strtoupper($latestState), ['STARTED', 'AWAIT_3DS', ''], true) ? 'pending' : 'failed'
        );
    }

    protected function accessToken(): string
    {
        $response = Http::withHeaders([
            'Authorization' => 'Basic ' . $this->config['api_key'],
            'Content-Type' => 'application/vnd.ni-identity.v1+json',
            'Accept' => 'application/vnd.ni-identity.v1+json',
        ])->post(rtrim($this->config['base_url'], '/') . '/identity/auth/access-token', (object) []);

        $token = (string) data_get($response->json(), 'access_token', '');

        if ($token === '') {
            throw new \Exception('Unable to obtain N-Genius access token: ' . $response->body());
        }

        return $token;
    }
}
