<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Models\PaymentSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class PaytabsGateway extends BaseGateway
{
    public function needyConfig(): array
    {
        return [
            'profile_id',
            'secret_key',
            'base_url'
        ];
    }

    protected function requestPayment(PaymentRequestData $payment): PaymentResponseData
    {
        $config = $this->config;

        $params = [
            'profile_id' => $config['profile_id'],
            'tran_type' => 'sale',
            'tran_class' => 'ecom',
            'cart_id' => $payment->orderId,
            'cart_currency' => $payment->currency ?: 'USD',
            'cart_amount' => $payment->amount,
            'hide_shipping' => true,
            'cart_description' => $payment->meta['description'] ?? ('Payment for order ' . $payment->orderId),
            'paypage_lang' => 'en',
            'callback' => $this->callbackUrl(),
            'return' => $this->successUrl(),
            'customer_ref' => $payment->orderId,
            'customer_details' => [
                'name' => $payment->customer->name ?? 'Not Available',
                'email' => $payment->customer->email ?? 'not-available@example.com',
                'phone' => (string) ($payment->meta['phone'] ?? '0000000000'),
                'street1' => 'Not Available',
                'city' => 'Not Available',
                'state' => 'Not Available',
                'country' => 'Not Available',
                'zip' => '00000',
            ],
            'valu_down_payment' => '0',
            'tokenise' => 1,
        ];

        $baseUrl = $config['base_url'] ?? 'https://secure-global.paytabs.com';

        try {
            $response = Http::withHeaders([
                'Authorization' => $config['secret_key'],
                'Content-Type' => 'application/json',
            ])->post($baseUrl.'/payment/request', $params);

            $response = $response->json();
            if($response['code'] == 1) {
                throw new \Exception($response['message']);
            }
            return new PaymentResponseData(
                true,
                $this->gatewayName,
                $response['tran_ref'] ?? null,
                $response['redirect_url'] ?? null,
                'pending',
                false,
                'PayTabs payment link generated successfully.',
                $response
            );
        } catch (\Throwable $e) {
            throw new \Exception("Paytabs Payment Initialization Failed: " . $e->getMessage());
        }
    }

    protected function verifyPayment(PaymentSession $session, Request $request): PaymentResponseData
    {
        $baseUrl = $this->config['base_url'] ?? 'https://secure-global.paytabs.com';

        // Query the transaction stored at initiation directly from PayTabs.
        $response = Http::withHeaders([
            'Authorization' => $this->config['secret_key'],
            'Content-Type' => 'application/json',
        ])->post(rtrim($baseUrl, '/') . '/payment/query', [
            'profile_id' => $this->config['profile_id'],
            'tran_ref' => (string) $session->payment_id,
        ]);

        $json = (array) $response->json();
        $responseStatus = (string) data_get($json, 'payment_result.response_status', '');

        if ($response->successful() && $responseStatus === 'A') {
            return $this->verified($session, $session->payment_id, $json, 'PayTabs payment verified.');
        }

        return $this->unverified(
            $session,
            "PayTabs reports response_status '" . ($responseStatus ?: 'unknown') . "'.",
            $json,
            $responseStatus === 'C' ? 'cancelled' : 'failed'
        );
    }
}
