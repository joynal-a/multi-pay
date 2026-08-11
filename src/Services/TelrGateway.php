<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Models\PaymentSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Telr hosted payment page (gateway/order.json API).
 */
class TelrGateway extends BaseGateway
{
    protected const ENDPOINT = 'https://secure.telr.com/gateway/order.json';

    public function needyConfig(): array
    {
        return [
            'store_id',
            'auth_key',
        ];
    }

    protected function requestPayment(PaymentRequestData $payment): PaymentResponseData
    {
        $payload = [
            'method' => 'create',
            'store' => (int) $this->config['store_id'],
            'authkey' => $this->config['auth_key'],
            'order' => [
                'cartid' => $payment->orderId,
                'test' => (string) ($this->rawConfig['test_mode'] ?? '1'),
                'amount' => number_format($payment->amount, 2, '.', ''),
                'currency' => strtoupper($payment->currency),
                'description' => $payment->meta['description'] ?? ('Payment for order ' . $payment->orderId),
            ],
            'return' => [
                'authorised' => $this->successUrl(),
                'declined' => $this->failureUrl(),
                'cancelled' => $this->cancelUrl(),
            ],
        ];

        try {
            $response = Http::acceptJson()->post(self::ENDPOINT, $payload);
            $json = (array) $response->json();

            if (!$response->successful() || isset($json['error'])) {
                throw new \Exception(json_encode($json['error'] ?? $json) ?: $response->body());
            }

            return new PaymentResponseData(
                true,
                $this->gatewayName,
                data_get($json, 'order.ref'),
                data_get($json, 'order.url'),
                'pending',
                false,
                'Telr payment page generated successfully.',
                $json
            );
        } catch (\Throwable $e) {
            throw new \Exception('Telr Payment Initialization Failed: ' . $e->getMessage());
        }
    }

    protected function verifyPayment(PaymentSession $session, Request $request): PaymentResponseData
    {
        // Check the order reference stored at initiation against Telr.
        $response = Http::acceptJson()->post(self::ENDPOINT, [
            'method' => 'check',
            'store' => (int) $this->config['store_id'],
            'authkey' => $this->config['auth_key'],
            'order' => ['ref' => (string) $session->payment_id],
        ]);

        $json = (array) $response->json();
        $statusCode = (int) data_get($json, 'order.status.code', 0);
        $statusText = strtolower((string) data_get($json, 'order.status.text', ''));

        // 3 = paid, 2 = authorised
        if ($response->successful() && in_array($statusCode, [2, 3], true)) {
            $paymentId = (string) data_get($json, 'order.transaction.ref', $session->payment_id);

            return $this->verified($session, $paymentId, $json, 'Telr payment verified.');
        }

        $status = match ($statusCode) {
            1 => 'pending',
            -1 => 'cancelled',
            -3 => 'expired',
            default => 'failed',
        };

        return $this->unverified(
            $session,
            "Telr reports order status '" . ($statusText ?: $statusCode) . "'.",
            $json,
            $status
        );
    }
}
