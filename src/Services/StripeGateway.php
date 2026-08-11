<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Models\PaymentSession;
use Illuminate\Http\Request;
use Stripe\Checkout\Session;
use Stripe\Stripe;

class StripeGateway extends BaseGateway
{
    protected function requestPayment(PaymentRequestData $payment): PaymentResponseData
    {
        $config = $this->config;

        Stripe::setApiKey($config['secret_key']);

        try{
            $response = Session::create([
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price_data' => [
                        'currency' => $payment->currency ?: 'USD',
                        'product_data' => [
                            'name' => $payment->meta['title'] ?? $payment->orderId,
                            'description' => $payment->meta['description'] ?? 'Payment for order ' . $payment->orderId,
                        ],
                        'unit_amount' => $this->minorAmount($payment->amount, $payment->currency ?: 'USD'),
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'client_reference_id' => $payment->orderId,
                'success_url' => $this->successUrl() . '?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $this->cancelUrl(),
            ]);

            return new PaymentResponseData(
                true,
                $this->gatewayName,
                $response->id,
                $response->url,
                'pending',
                false,
                'Stripe payment link generated successfully.',
                $response->toArray()
            );

        } catch (\Exception $e) {
            throw new \Exception("Stripe Payment Initialization Failed: " . $e->getMessage());
        }
    }

    protected function verifyPayment(PaymentSession $session, Request $request): PaymentResponseData
    {
        Stripe::setApiKey($this->config['secret_key']);

        // The checkout session id was stored at initiation — verify against
        // Stripe directly instead of trusting anything in the request.
        $checkout = Session::retrieve((string) $session->payment_id);

        if ($checkout->payment_status === 'paid') {
            return $this->verified($session, $checkout->id, $checkout->toArray(), 'Stripe payment verified.');
        }

        $status = $checkout->status === 'expired' ? 'expired' : 'pending';

        return $this->unverified(
            $session,
            "Stripe reports payment_status '{$checkout->payment_status}'.",
            $checkout->toArray(),
            $status
        );
    }

    public function needyConfig(): array
    {
        return [
            'secret_key',
            'public_key'
        ];
    }
}
