<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Models\PaymentSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class WorldpayGateway extends BaseGateway
{
    /**
     * lastEvent values that mean the money was taken (or authorised for
     * capture).
     */
    protected const PAID_EVENTS = ['authorized', 'sentforsettlement', 'settled'];

    public function needyConfig(): array
    {
        return [
            'authorization',
            'merchant_entity',
            'narrative_line1',
            'base_url',
        ];
    }

    protected function requestPayment(PaymentRequestData $payment): PaymentResponseData
    {
        $payload = [
            'transactionReference' => $payment->orderId,
            'merchant' => [
                'entity' => $this->config['merchant_entity'],
            ],
            'narrative' => [
                'line1' => $this->config['narrative_line1'],
            ],
            'value' => [
                'currency' => $payment->currency,
                'amount' => $this->amountInMinorUnits($payment->amount),
            ],
            'description' => $payment->meta['description'] ?? ('Payment for order ' . $payment->orderId),
            'resultURLs' => [
                'successURL' => $this->successUrl(),
                'pendingURL' => $this->pendingUrl(),
                'failureURL' => $this->failureUrl(),
                'errorURL' => $this->errorUrl(),
                'cancelURL' => $this->cancelUrl(),
                'expiryURL' => $this->expiryUrl(),
            ],
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => $this->config['authorization'],
                'Content-Type' => 'application/vnd.worldpay.payment_pages-v1.hal+json',
                'Accept' => 'application/vnd.worldpay.payment_pages-v1.hal+json',
            ])->post(rtrim($this->config['base_url'], '/') . '/payment_pages', $payload);

            $json = $response->json();

            if (!$response->successful()) {
                throw new \Exception(is_array($json) ? json_encode($json) : $response->body());
            }

            return new PaymentResponseData(
                true,
                $this->gatewayName,
                $json['transactionReference'] ?? $payment->orderId,
                $json['url'] ?? null,
                'pending',
                false,
                'Worldpay Hosted Payment Page generated successfully.',
                is_array($json) ? $json : []
            );
        } catch (\Throwable $e) {
            throw new \Exception('Worldpay Payment Initialization Failed: ' . $e->getMessage());
        }
    }

    protected function verifyPayment(PaymentSession $session, Request $request): PaymentResponseData
    {
        // Query Worldpay's payment queries API for our transactionReference.
        $response = Http::withHeaders([
            'Authorization' => $this->config['authorization'],
            'Accept' => 'application/vnd.worldpay.payment_queries-v1.hal+json',
        ])->get(rtrim($this->config['base_url'], '/') . '/paymentQueries/payments', [
            'transactionReference' => (string) $session->order_id,
        ]);

        $json = (array) $response->json();
        $payments = (array) data_get($json, '_embedded.payments', []);

        foreach ($payments as $paymentRow) {
            $lastEvent = strtolower((string) ($paymentRow['lastEvent'] ?? ''));

            if (in_array($lastEvent, self::PAID_EVENTS, true)) {
                return $this->verified($session, $session->payment_id, $json, 'Worldpay payment verified.');
            }
        }

        $latestEvent = (string) data_get($payments, '0.lastEvent', '');

        return $this->unverified(
            $session,
            $payments === []
                ? 'Worldpay has no payment for this transaction reference.'
                : "Worldpay reports lastEvent '{$latestEvent}'.",
            $json,
            $payments === [] ? 'pending' : 'failed'
        );
    }
}
