<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Models\PaymentSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * FawryPay (Egypt) hosted checkout.
 * base_url: https://atfawry.fawrystaging.com (staging) / https://www.atfawry.com (live)
 */
class FawryGateway extends BaseGateway
{
    public function needyConfig(): array
    {
        return [
            'merchant_code',
            'secure_key',
            'base_url',
        ];
    }

    protected function requestPayment(PaymentRequestData $payment): PaymentResponseData
    {
        $price = number_format($payment->amount, 2, '.', '');
        $returnUrl = $this->successUrl();

        // signature = sha256(merchantCode + merchantRefNum + returnUrl
        //             + itemId + quantity + price + secureKey)
        $signature = hash('sha256',
            $this->config['merchant_code']
            . $payment->orderId
            . $returnUrl
            . $payment->orderId . '1' . $price
            . $this->config['secure_key']
        );

        $payload = [
            'merchantCode' => $this->config['merchant_code'],
            'merchantRefNum' => $payment->orderId,
            'customerName' => $payment->customer->name ?? 'Customer',
            'customerMobile' => (string) ($payment->meta['phone'] ?? '01000000000'),
            'customerEmail' => $payment->customer->email ?? 'customer@example.com',
            'language' => 'en-gb',
            'chargeItems' => [
                [
                    'itemId' => $payment->orderId,
                    'description' => $payment->meta['description'] ?? ('Payment for order ' . $payment->orderId),
                    'price' => $price,
                    'quantity' => 1,
                ],
            ],
            'returnUrl' => $returnUrl,
            'authCaptureModePayment' => false,
            'signature' => $signature,
        ];

        try {
            $response = Http::acceptJson()
                ->post(rtrim($this->config['base_url'], '/') . '/fawrypay-api/api/payments/init', $payload);

            if (!$response->successful()) {
                throw new \Exception($response->body());
            }

            // Fawry returns the checkout URL as a plain string body.
            $body = trim((string) $response->body(), " \t\n\r\"");

            if (!str_starts_with($body, 'http')) {
                throw new \Exception('Fawry did not return a checkout URL: ' . $body);
            }

            return new PaymentResponseData(
                true,
                $this->gatewayName,
                $payment->orderId,
                $body,
                'pending',
                false,
                'Fawry checkout URL generated successfully.',
                ['checkout_url' => $body]
            );
        } catch (\Throwable $e) {
            throw new \Exception('Fawry Payment Initialization Failed: ' . $e->getMessage());
        }
    }

    protected function verifyPayment(PaymentSession $session, Request $request): PaymentResponseData
    {
        // Payment Status V2 API, signed with our secure key.
        $signature = hash('sha256',
            $this->config['merchant_code']
            . (string) $session->order_id
            . $this->config['secure_key']
        );

        $response = Http::acceptJson()->get(
            rtrim($this->config['base_url'], '/') . '/ECommerceWeb/Fawry/payments/status/v2',
            [
                'merchantCode' => $this->config['merchant_code'],
                'merchantRefNumber' => (string) $session->order_id,
                'signature' => $signature,
            ]
        );

        $json = (array) $response->json();
        $orderStatus = strtoupper((string) ($json['orderStatus'] ?? $json['paymentStatus'] ?? ''));
        $amountOk = (float) ($json['paymentAmount'] ?? $json['orderAmount'] ?? 0) >= (float) $session->amount;

        if ($response->successful() && $orderStatus === 'PAID' && $amountOk) {
            $paymentId = (string) ($json['fawryRefNumber'] ?? $session->payment_id);

            return $this->verified($session, $paymentId, $json, 'Fawry payment verified.');
        }

        $mapped = match ($orderStatus) {
            'NEW', 'UNPAID' => 'pending',
            'EXPIRED' => 'expired',
            'CANCELED', 'CANCELLED' => 'cancelled',
            default => 'failed',
        };

        return $this->unverified(
            $session,
            "Fawry reports order status '" . ($orderStatus ?: 'unknown') . "'.",
            $json,
            $mapped
        );
    }
}
