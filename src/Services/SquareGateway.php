<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Models\PaymentSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SquareGateway extends BaseGateway
{
    public function needyConfig(): array
    {
        return [
            'square_access_token',
            'square_location',
            'square_environment' // sandbox or production
        ];
    }

    protected function requestPayment(PaymentRequestData $payment): PaymentResponseData
    {
        $config = $this->config;

        $idempotencyKey = (string) Str::uuid();
        $redirectUrl = $this->successUrl();
        $currency = $payment->currency ?: 'USD';

        $body = [
            'idempotency_key' => $idempotencyKey,
            // Either 'order' (for full order object) OR 'quick_pay' (single ad‑hoc amount)
            'quick_pay' => [
                'name' => $payment->meta['title'] ?? ('Order ' . $payment->orderId),
                'location_id' => $config['square_location'],
                'price_money' => [
                    'amount' => $this->minorAmount($payment->amount, $currency),
                    'currency' => $currency,
                ],
            ],
            'checkout_options' => [
                'redirect_url' => $redirectUrl,
            ],
            'pre_populated_data' => array_filter([
                'buyer_email' => $payment->customer->email,
            ]),
        ];

        try {
            $res = Http::withHeaders($this->headers())
                ->post($this->baseUrl() . '/online-checkout/payment-links', $body);
            $json = $res->json();

            if ($res->successful()) {
                return new PaymentResponseData(
                    true,
                    $this->gatewayName,
                    $json['payment_link']['id'] ?? null,
                    $json['payment_link']['long_url'] ?? null,
                    'pending',
                    false,
                    'Square payment link generated successfully.',
                    $json
                );
            } else {
                throw new \Exception(json_encode($json));
            }
        } catch (\Exception $e) {
            throw new \Exception("Square Payment initiation failed: " . $e->getMessage());
        }
    }

    protected function verifyPayment(PaymentSession $session, Request $request): PaymentResponseData
    {
        // The order behind the payment link is the source of truth: a paid
        // order carries tenders (completed payments applied to it).
        $orderId = (string) data_get($session->raw_response, 'payment_link.order_id', '');

        if ($orderId === '') {
            $linkRes = Http::withHeaders($this->headers())
                ->get($this->baseUrl() . '/online-checkout/payment-links/' . urlencode((string) $session->payment_id));
            $orderId = (string) data_get($linkRes->json(), 'payment_link.order_id', '');
        }

        if ($orderId === '') {
            return $this->unverified($session, 'Square order id could not be resolved for this session.');
        }

        $orderRes = Http::withHeaders($this->headers())
            ->get($this->baseUrl() . '/orders/' . urlencode($orderId));

        $json = (array) $orderRes->json();
        $order = (array) ($json['order'] ?? []);
        $state = (string) ($order['state'] ?? '');
        $tenders = (array) ($order['tenders'] ?? []);

        if ($orderRes->successful() && $tenders !== [] && $state !== 'CANCELED') {
            $paymentId = (string) data_get($tenders, '0.payment_id', $session->payment_id);

            return $this->verified($session, $paymentId, $json, 'Square payment verified.');
        }

        return $this->unverified(
            $session,
            "Square reports order state '" . ($state ?: 'unknown') . "' with " . count($tenders) . " tender(s).",
            $json,
            $state === 'CANCELED' ? 'cancelled' : 'pending'
        );
    }

    protected function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->config['square_access_token'],
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
            'Square-Version' => '2024-07-17',
        ];
    }

    protected function baseUrl(): string
    {
        return ($this->config['square_environment'] == 'production')
            ? 'https://connect.squareup.com/v2'
            : 'https://connect.squareupsandbox.com/v2';
    }
}
