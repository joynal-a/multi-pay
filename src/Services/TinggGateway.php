<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Models\PaymentSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class TinggGateway extends BaseGateway
{
    public function needyConfig(): array
    {
        return [
            'api_key',
            'client_id',
            'client_secret',
            'auth_base_url',
            'base_url',
            'service_code',
            'country_code',
            'currency_code',
        ];
    }

    protected function requestPayment(PaymentRequestData $payment): PaymentResponseData
    {
        $token = $this->accessToken();
        $description = $payment->meta['description'] ?? ('Payment for order ' . $payment->orderId);
        $now = now()->utc();

        $payload = [
            'customer_first_name' => $this->extractFirstName($payment->customer->name ?? 'Customer'),
            'customer_last_name' => $this->extractLastName($payment->customer->name ?? 'Customer'),
            'msisdn' => (string) ($payment->meta['phone'] ?? '254700000000'),
            'account_number' => substr((string) ($payment->meta['account_number'] ?? $payment->orderId), 0, 15),
            'request_amount' => (string) $payment->amount,
            'merchant_transaction_id' => $payment->orderId,
            'service_code' => $this->config['service_code'],
            'country_code' => $payment->meta['country_code'] ?? $this->config['country_code'],
            'currency_code' => $payment->meta['currency_code'] ?? $this->config['currency_code'] ?? $payment->currency,
            'callback_url' => $this->callbackUrl(),
            'fail_redirect_url' => $this->failureUrl(),
            'success_redirect_url' => $this->successUrl(),
            'request_description' => $description,
            'customer_email' => $payment->customer->email ?? '[email protected]',
            'due_date' => $payment->meta['due_date'] ?? $now->copy()->addHour()->format('Y-m-d H:i:s'),
        ];

        if (!empty($payment->meta['invoice_number'])) {
            $payload['invoice_number'] = $payment->meta['invoice_number'];
        }

        if (array_key_exists('raise_invoice', $payment->meta)) {
            $payload['raise_invoice'] = (bool) $payment->meta['raise_invoice'];
        }

        if (!empty($payment->meta['payment_option_code'])) {
            $payload['payment_option_code'] = $payment->meta['payment_option_code'];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'apiKey' => $this->config['api_key'],
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post(rtrim($this->config['base_url'], '/') . '/v3/checkout-api/checkout-request/express-request', $payload);

            $json = $response->json();

            if (!$response->successful()) {
                throw new \Exception(is_array($json) ? json_encode($json) : $response->body());
            }

            $shortUrl = $json['results']['short_url'] ?? null;
            $longUrl = $json['results']['long_url'] ?? null;

            return new PaymentResponseData(
                true,
                $this->gatewayName,
                $payment->orderId,
                $shortUrl ?: $longUrl,
                'pending',
                false,
                'Tingg checkout URL generated successfully.',
                is_array($json) ? $json : []
            );
        } catch (\Throwable $e) {
            throw new \Exception('Tingg Payment Initialization Failed: ' . $e->getMessage());
        }
    }

    protected function verifyPayment(PaymentSession $session, Request $request): PaymentResponseData
    {
        // Query the checkout request by our merchant transaction id (order
        // id). Status code 178 means fully paid in Tingg's checkout API.
        $token = $this->accessToken();

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'apiKey' => $this->config['api_key'],
            'Accept' => 'application/json',
        ])->get(rtrim($this->config['base_url'], '/') . '/v3/checkout-api/checkout-request/query-status', [
            'merchant_transaction_id' => (string) $session->order_id,
            'service_code' => $this->config['service_code'],
        ]);

        $json = (array) $response->json();
        $statusCode = (string) (data_get($json, 'results.request_status_code')
            ?? data_get($json, 'data.request_status_code', ''));
        $amountPaid = (float) (data_get($json, 'results.amount_paid')
            ?? data_get($json, 'data.amount_paid', 0));

        if ($response->successful() && ($statusCode === '178' || $amountPaid >= (float) $session->amount && $amountPaid > 0)) {
            return $this->verified($session, $session->payment_id, $json, 'Tingg payment verified.');
        }

        return $this->unverified(
            $session,
            "Tingg reports request status code '" . ($statusCode ?: 'unknown') . "'.",
            $json,
            in_array($statusCode, ['129', '176'], true) ? 'pending' : 'failed'
        );
    }

    protected function accessToken(): string
    {
        $response = Http::withHeaders([
            'apiKey' => $this->config['api_key'],
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post(rtrim($this->config['auth_base_url'], '/') . '/v1/oauth/token/request', [
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'grant_type' => 'client_credentials',
        ]);

        $json = $response->json();

        if (!$response->successful() || empty($json['access_token'])) {
            throw new \Exception('Unable to obtain Tingg access token: ' . (is_array($json) ? json_encode($json) : $response->body()));
        }

        return (string) $json['access_token'];
    }

    protected function extractFirstName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));

        return $parts[0] ?? 'Customer';
    }

    protected function extractLastName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));

        if (!$parts || count($parts) < 2) {
            return 'Customer';
        }

        array_shift($parts);

        return implode(' ', $parts);
    }
}
