<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Models\PaymentSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Moyasar hosted invoice page. base_url: https://api.moyasar.com
 */
class MoyasarGateway extends BaseGateway
{
    public function needyConfig(): array
    {
        return [
            'secret_key',
            'base_url',
        ];
    }

    protected function requestPayment(PaymentRequestData $payment): PaymentResponseData
    {
        $currency = strtoupper($payment->currency ?: 'SAR');

        $payload = [
            'amount' => $this->minorAmount($payment->amount, $currency),
            'currency' => $currency,
            'description' => $payment->meta['description'] ?? ('Payment for order ' . $payment->orderId),
            'callback_url' => $this->callbackUrl(),
            'success_url' => $this->successUrl(),
            'back_url' => $this->cancelUrl(),
            'metadata' => [
                'order_id' => $payment->orderId,
                'payment_session_id' => $this->sessionIdentifier(),
            ],
        ];

        try {
            $response = Http::withBasicAuth($this->config['secret_key'], '')
                ->acceptJson()
                ->post(rtrim($this->config['base_url'], '/') . '/v1/invoices', $payload);

            $json = (array) $response->json();

            if (!$response->successful()) {
                throw new \Exception(json_encode($json) ?: $response->body());
            }

            return new PaymentResponseData(
                true,
                $this->gatewayName,
                $json['id'] ?? null,
                $json['url'] ?? null,
                'pending',
                false,
                'Moyasar invoice generated successfully.',
                $json
            );
        } catch (\Throwable $e) {
            throw new \Exception('Moyasar Payment Initialization Failed: ' . $e->getMessage());
        }
    }

    protected function verifyPayment(PaymentSession $session, Request $request): PaymentResponseData
    {
        // Fetch the invoice stored at initiation straight from Moyasar.
        $response = Http::withBasicAuth($this->config['secret_key'], '')
            ->acceptJson()
            ->get(rtrim($this->config['base_url'], '/') . '/v1/invoices/' . urlencode((string) $session->payment_id));

        $json = (array) $response->json();
        $status = strtolower((string) ($json['status'] ?? ''));

        if ($response->successful() && $status === 'paid') {
            return $this->verified($session, $session->payment_id, $json, 'Moyasar payment verified.');
        }

        $mapped = match ($status) {
            'initiated', 'pending' => 'pending',
            'canceled' => 'cancelled',
            'expired' => 'expired',
            default => 'failed',
        };

        return $this->unverified(
            $session,
            "Moyasar reports invoice status '" . ($status ?: 'unknown') . "'.",
            $json,
            $mapped
        );
    }
}
