<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Models\PaymentSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Checkout.com Hosted Payments Page.
 * Sandbox base_url: https://api.sandbox.checkout.com — live: https://api.checkout.com
 */
class CheckoutComGateway extends BaseGateway
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
        $currency = strtoupper($payment->currency);

        $payload = [
            'amount' => $this->minorAmount($payment->amount, $currency),
            'currency' => $currency,
            'reference' => $payment->orderId,
            'description' => $payment->meta['description'] ?? ('Payment for order ' . $payment->orderId),
            'customer' => array_filter([
                'email' => $payment->customer->email,
                'name' => $payment->customer->name,
            ]),
            'success_url' => $this->successUrl(),
            'cancel_url' => $this->cancelUrl(),
            'failure_url' => $this->failureUrl(),
        ];

        if ($payload['customer'] === []) {
            unset($payload['customer']);
        }

        try {
            $response = Http::withToken($this->config['secret_key'])
                ->acceptJson()
                ->post(rtrim($this->config['base_url'], '/') . '/hosted-payments', $payload);

            $json = (array) $response->json();

            if (!$response->successful()) {
                throw new \Exception(json_encode($json) ?: $response->body());
            }

            return new PaymentResponseData(
                true,
                $this->gatewayName,
                $json['id'] ?? null,
                data_get($json, '_links.redirect.href'),
                'pending',
                false,
                'Checkout.com hosted payment page generated successfully.',
                $json
            );
        } catch (\Throwable $e) {
            throw new \Exception('Checkout.com Payment Initialization Failed: ' . $e->getMessage());
        }
    }

    protected function verifyPayment(PaymentSession $session, Request $request): PaymentResponseData
    {
        // Query the hosted payment session stored at initiation.
        $response = Http::withToken($this->config['secret_key'])
            ->acceptJson()
            ->get(rtrim($this->config['base_url'], '/') . '/hosted-payments/' . urlencode((string) $session->payment_id));

        $json = (array) $response->json();
        $status = strtolower((string) ($json['status'] ?? ''));

        if ($response->successful() && $status === 'payment received') {
            $paymentId = (string) data_get($json, 'payment_id', $session->payment_id);

            return $this->verified($session, $paymentId, $json, 'Checkout.com payment verified.');
        }

        return $this->unverified(
            $session,
            "Checkout.com reports hosted payment status '" . ($status ?: 'unknown') . "'.",
            $json,
            $status === 'expired' ? 'expired' : 'pending'
        );
    }
}
