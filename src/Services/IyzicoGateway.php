<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Models\PaymentSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * iyzico Checkout Form (hosted). Sandbox base_url: https://sandbox-api.iyzipay.com
 */
class IyzicoGateway extends BaseGateway
{
    public function needyConfig(): array
    {
        return [
            'api_key',
            'secret_key',
            'base_url',
        ];
    }

    protected function requestPayment(PaymentRequestData $payment): PaymentResponseData
    {
        $price = number_format($payment->amount, 2, '.', '');
        $name = trim((string) ($payment->customer->name ?? 'Customer'));
        $parts = preg_split('/\s+/', $name) ?: ['Customer'];
        $firstName = $parts[0] ?? 'Customer';
        $lastName = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : 'Customer';
        $email = $payment->customer->email ?? 'customer@example.com';

        $address = [
            'contactName' => $name ?: 'Customer',
            'city' => (string) ($payment->meta['city'] ?? 'Istanbul'),
            'country' => (string) ($payment->meta['country'] ?? 'Turkey'),
            'address' => (string) ($payment->meta['address'] ?? 'Not Available'),
        ];

        $payload = [
            'locale' => (string) ($payment->meta['locale'] ?? 'en'),
            'conversationId' => $payment->orderId,
            'price' => $price,
            'paidPrice' => $price,
            'currency' => strtoupper($payment->currency ?: 'TRY'),
            'basketId' => $payment->orderId,
            'paymentGroup' => 'PRODUCT',
            'callbackUrl' => $this->callbackUrl(),
            'buyer' => [
                'id' => $payment->orderId,
                'name' => $firstName,
                'surname' => $lastName,
                'gsmNumber' => (string) ($payment->meta['phone'] ?? '+900000000000'),
                'email' => $email,
                'identityNumber' => (string) ($payment->meta['identity_number'] ?? '11111111111'),
                'registrationAddress' => $address['address'],
                'ip' => request()->ip() ?: '127.0.0.1',
                'city' => $address['city'],
                'country' => $address['country'],
            ],
            'shippingAddress' => $address,
            'billingAddress' => $address,
            'basketItems' => [
                [
                    'id' => $payment->orderId,
                    'name' => (string) ($payment->meta['title'] ?? ('Order ' . $payment->orderId)),
                    'category1' => (string) ($payment->meta['category'] ?? 'General'),
                    'itemType' => 'VIRTUAL',
                    'price' => $price,
                ],
            ],
        ];

        try {
            $json = $this->call('/payment/iyzipos/checkoutform/initialize/auth/ecom', $payload);

            if (($json['status'] ?? '') !== 'success') {
                throw new \Exception((string) ($json['errorMessage'] ?? json_encode($json)));
            }

            return new PaymentResponseData(
                true,
                $this->gatewayName,
                $json['token'] ?? null,
                $json['paymentPageUrl'] ?? null,
                'pending',
                false,
                'iyzico checkout form generated successfully.',
                $json
            );
        } catch (\Throwable $e) {
            throw new \Exception('iyzico Payment Initialization Failed: ' . $e->getMessage());
        }
    }

    protected function verifyPayment(PaymentSession $session, Request $request): PaymentResponseData
    {
        // Retrieve the checkout form result by the token stored at initiation.
        $json = $this->call('/payment/iyzipos/checkoutform/auth/ecom/detail', [
            'locale' => 'en',
            'conversationId' => (string) $session->order_id,
            'token' => (string) $session->payment_id,
        ]);

        $paymentStatus = strtoupper((string) ($json['paymentStatus'] ?? ''));

        if (($json['status'] ?? '') === 'success' && $paymentStatus === 'SUCCESS') {
            $paymentId = (string) ($json['paymentId'] ?? $session->payment_id);

            return $this->verified($session, $paymentId, $json, 'iyzico payment verified.');
        }

        return $this->unverified(
            $session,
            "iyzico reports payment status '" . ($paymentStatus ?: (string) ($json['errorMessage'] ?? 'unknown')) . "'.",
            $json,
            $paymentStatus === 'INIT_THREEDS' || $paymentStatus === 'CALLBACK_THREEDS' ? 'pending' : 'failed'
        );
    }

    /**
     * iyzico IYZWSv2 HMAC-SHA256 request signing.
     */
    protected function call(string $uriPath, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}';
        $randomKey = str_replace('.', '', uniqid('', true));
        $signature = hash_hmac('sha256', $randomKey . $uriPath . $body, $this->config['secret_key']);
        $authorization = 'IYZWSv2 ' . base64_encode(
            'apiKey:' . $this->config['api_key'] . '&randomKey:' . $randomKey . '&signature:' . $signature
        );

        $response = Http::withHeaders([
            'Authorization' => $authorization,
            'x-iyzi-rnd' => $randomKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->withBody($body, 'application/json')
            ->post(rtrim($this->config['base_url'], '/') . $uriPath);

        return (array) $response->json();
    }
}
