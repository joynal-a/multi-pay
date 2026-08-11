<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Models\PaymentSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class MollieGateway extends BaseGateway
{
    public function needyConfig(): array
    {
        return [
            'api_key',
            'base_url',
            'send_webhook_url',
        ];
    }

    protected function requestPayment(PaymentRequestData $payment): PaymentResponseData
    {
        $callbackUrl = $this->callbackUrl();

        $payload = [
            'amount' => [
                'currency' => $payment->currency,
                'value' => number_format($payment->amount, 2, '.', ''),
            ],
            'description' => $payment->meta['description'] ?? ('Payment for order ' . $payment->orderId),
            'redirectUrl' => $this->successUrl(),
            'cancelUrl' => $this->cancelUrl(),
            'metadata' => [
                'order_id' => $payment->orderId,
                'payment_session_id' => $this->sessionIdentifier(),
                'customer' => $payment->customer->toArray(),
                'meta' => $payment->meta,
            ],
        ];

        if ($this->shouldSendWebhookUrl($callbackUrl)) {
            $payload['webhookUrl'] = $callbackUrl;
        }

        if (!empty($payment->meta['method'])) {
            $payload['method'] = $payment->meta['method'];
        }

        if (!empty($payment->meta['locale'])) {
            $payload['locale'] = $payment->meta['locale'];
        }

        if (!empty($payment->meta['sequence_type'])) {
            $payload['sequenceType'] = $payment->meta['sequence_type'];
        }

        try {
            $response = Http::withToken($this->config['api_key'])
                ->acceptJson()
                ->post(rtrim($this->config['base_url'], '/') . '/v2/payments', $payload);

            $json = $response->json();

            if (!$response->successful()) {
                throw new \Exception(is_array($json) ? json_encode($json) : $response->body());
            }

            return new PaymentResponseData(
                true,
                $this->gatewayName,
                $json['id'] ?? null,
                $json['_links']['checkout']['href'] ?? null,
                $json['status'] ?? 'pending',
                false,
                'Mollie checkout URL generated successfully.',
                is_array($json) ? $json : []
            );
        } catch (\Throwable $e) {
            throw new \Exception('Mollie Payment Initialization Failed: ' . $e->getMessage());
        }
    }

    protected function verifyPayment(PaymentSession $session, Request $request): PaymentResponseData
    {
        // Fetch the payment stored at initiation straight from Mollie.
        $response = Http::withToken($this->config['api_key'])
            ->acceptJson()
            ->get(rtrim($this->config['base_url'], '/') . '/v2/payments/' . urlencode((string) $session->payment_id));

        $json = (array) $response->json();
        $status = (string) ($json['status'] ?? '');

        if ($response->successful() && $status === 'paid') {
            return $this->verified($session, $session->payment_id, $json, 'Mollie payment verified.');
        }

        $mapped = in_array($status, ['canceled', 'expired', 'failed'], true)
            ? ($status === 'canceled' ? 'cancelled' : $status)
            : 'pending';

        return $this->unverified(
            $session,
            "Mollie reports payment status '" . ($status ?: 'unknown') . "'.",
            $json,
            $mapped
        );
    }

    protected function shouldSendWebhookUrl(string $callbackUrl): bool
    {
        $flag = $this->config['send_webhook_url'] ?? true;

        return $this->normalizeWebhookFlag($flag) && $this->isPublicWebhookUrl($callbackUrl);
    }

    protected function normalizeWebhookFlag(mixed $flag): bool
    {
        if (is_bool($flag)) {
            return $flag;
        }

        if (is_string($flag)) {
            return filter_var($flag, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        }

        return (bool) $flag;
    }

    protected function isPublicWebhookUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (!$host || $host === 'localhost') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if ($host === '127.0.0.1' || $host === '::1') {
                return false;
            }

            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) !== false;
        }

        return true;
    }
}
