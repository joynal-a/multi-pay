<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Models\PaymentSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class MercadoPagoGateway extends BaseGateway
{
    public function needyConfig(): array
    {
        return [
            'access_token',
            'base_url',
            'send_notification_url',
        ];
    }

    protected function requestPayment(PaymentRequestData $payment): PaymentResponseData
    {
        $callbackUrl = $this->callbackUrl();
        $description = $payment->meta['description'] ?? ('Payment for order ' . $payment->orderId);

        $payload = [
            'items' => [
                [
                    'title' => $payment->meta['title'] ?? $description,
                    'quantity' => (int) ($payment->meta['quantity'] ?? 1),
                    'currency_id' => $payment->currency,
                    'unit_price' => (float) $payment->amount,
                ],
            ],
            'external_reference' => $payment->orderId,
            'back_urls' => [
                'success' => $this->successUrl(),
                'pending' => $this->pendingUrl(),
                'failure' => $this->failureUrl(),
            ],
            'auto_return' => 'approved',
            'payer' => array_filter([
                'name' => $payment->customer->name,
                'email' => $payment->customer->email,
            ], static fn ($value) => $value !== null && $value !== ''),
            'metadata' => [
                'order_id' => $payment->orderId,
                'payment_session_id' => $this->sessionIdentifier(),
                'customer' => $payment->customer->toArray(),
                'meta' => $payment->meta,
            ],
        ];

        if ($this->shouldSendNotificationUrl($callbackUrl)) {
            $payload['notification_url'] = $callbackUrl;
        }

        try {
            $response = Http::withToken($this->config['access_token'])
                ->acceptJson()
                ->post(rtrim($this->config['base_url'], '/') . '/checkout/preferences', $payload);

            $json = $response->json();

            if (!$response->successful()) {
                throw new \Exception(is_array($json) ? json_encode($json) : $response->body());
            }

            return new PaymentResponseData(
                true,
                $this->gatewayName,
                $json['id'] ?? null,
                $json['init_point'] ?? null,
                'pending',
                false,
                'Mercado Pago checkout URL generated successfully.',
                is_array($json) ? $json : []
            );
        } catch (\Throwable $e) {
            throw new \Exception('Mercado Pago Payment Initialization Failed: ' . $e->getMessage());
        }
    }

    protected function verifyPayment(PaymentSession $session, Request $request): PaymentResponseData
    {
        // Search Mercado Pago for payments carrying our external_reference
        // (the order id) — never trust the redirect's query string.
        $response = Http::withToken($this->config['access_token'])
            ->acceptJson()
            ->get(rtrim($this->config['base_url'], '/') . '/v1/payments/search', [
                'external_reference' => (string) $session->order_id,
                'sort' => 'date_created',
                'criteria' => 'desc',
            ]);

        $json = (array) $response->json();
        $results = (array) ($json['results'] ?? []);

        foreach ($results as $paymentRow) {
            $status = (string) ($paymentRow['status'] ?? '');
            $amountOk = (float) ($paymentRow['transaction_amount'] ?? 0) >= (float) $session->amount;

            if ($status === 'approved' && $amountOk) {
                $paymentId = isset($paymentRow['id']) ? (string) $paymentRow['id'] : $session->payment_id;

                return $this->verified($session, $paymentId, $paymentRow, 'Mercado Pago payment verified.');
            }
        }

        $latestStatus = (string) data_get($results, '0.status', '');

        return $this->unverified(
            $session,
            $results === []
                ? 'Mercado Pago has no payment for this order reference.'
                : "Mercado Pago reports payment status '{$latestStatus}'.",
            $json,
            in_array($latestStatus, ['in_process', 'pending', ''], true) ? 'pending' : 'failed'
        );
    }

    protected function shouldSendNotificationUrl(string $callbackUrl): bool
    {
        $flag = $this->config['send_notification_url'] ?? true;

        return $this->normalizeFlag($flag) && $this->isPublicUrl($callbackUrl);
    }

    protected function normalizeFlag(mixed $flag): bool
    {
        if (is_bool($flag)) {
            return $flag;
        }

        if (is_string($flag)) {
            return filter_var($flag, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        }

        return (bool) $flag;
    }

    protected function isPublicUrl(string $url): bool
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
