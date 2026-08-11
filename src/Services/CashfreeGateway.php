<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Models\PaymentSession;
use Abedin\MultiPay\Support\AppRoutes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class CashfreeGateway extends BaseGateway
{
    public function needyConfig(): array
    {
        return [
            'client_id',
            'client_secret',
            'base_url',
            'api_version',
            'environment',
        ];
    }

    protected function requestPayment(PaymentRequestData $payment): PaymentResponseData
    {
        $sessionId = $this->sessionIdentifier();
        $payload = [
            'order_id' => $payment->orderId,
            'order_currency' => $payment->currency,
            'order_amount' => $payment->amount,
            'customer_details' => [
                'customer_id' => $payment->orderId,
                'customer_name' => $payment->customer->name ?? 'Customer',
                'customer_email' => $payment->customer->email ?? '[email protected]',
                'customer_phone' => (string) ($payment->meta['phone'] ?? '9999999999'),
            ],
            'order_meta' => [
                'return_url' => $this->successUrl() . '?order_id=' . urlencode($payment->orderId),
                'notify_url' => $this->callbackUrl(),
            ],
            'order_note' => $payment->meta['description'] ?? ('Payment for order ' . $payment->orderId),
        ];

        try {
            $response = Http::withHeaders([
                'x-client-id' => $this->config['client_id'],
                'x-client-secret' => $this->config['client_secret'],
                'x-api-version' => $this->config['api_version'],
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post(rtrim($this->config['base_url'], '/') . '/pg/orders', $payload);

            $json = $response->json();

            if (!$response->successful()) {
                throw new \Exception(is_array($json) ? json_encode($json) : $response->body());
            }

            return new PaymentResponseData(
                true,
                $this->gatewayName,
                $json['payment_session_id'] ?? null,
                AppRoutes::internalUrl('cashfree.checkout', $sessionId),
                $json['order_status'] ?? 'pending',
                true,
                'Cashfree checkout session generated successfully.',
                is_array($json) ? $json : []
            );
        } catch (\Throwable $e) {
            throw new \Exception('Cashfree Payment Initialization Failed: ' . $e->getMessage());
        }
    }

    protected function verifyPayment(PaymentSession $session, Request $request): PaymentResponseData
    {
        // Query the order stored at initiation straight from Cashfree.
        $response = Http::withHeaders([
            'x-client-id' => $this->config['client_id'],
            'x-client-secret' => $this->config['client_secret'],
            'x-api-version' => $this->config['api_version'],
            'Accept' => 'application/json',
        ])->get(rtrim($this->config['base_url'], '/') . '/pg/orders/' . urlencode((string) $session->order_id));

        $json = (array) $response->json();
        $orderStatus = strtoupper((string) ($json['order_status'] ?? ''));

        if ($response->successful() && $orderStatus === 'PAID') {
            return $this->verified($session, $session->payment_id, $json, 'Cashfree payment verified.');
        }

        $status = match ($orderStatus) {
            'ACTIVE' => 'pending',
            'EXPIRED' => 'expired',
            'TERMINATED' => 'cancelled',
            default => 'failed',
        };

        return $this->unverified(
            $session,
            "Cashfree reports order status '" . ($orderStatus ?: 'unknown') . "'.",
            $json,
            $status
        );
    }

    public function checkoutMode(): string
    {
        return strtolower((string) $this->config['environment']) === 'production'
            ? 'production'
            : 'sandbox';
    }
}
