<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Models\PaymentSession;
use Illuminate\Http\Request;

class RazorpayGateway extends BaseGateway
{
    protected function requestPayment(PaymentRequestData $payment): PaymentResponseData
    {
        $config = $this->config;

        $api = new \Razorpay\Api\Api(
            $config['public_key'],
            $config['secret_key']
        );

        try{
            $currency = $payment->currency ?: 'INR';
            $response = $api->paymentLink->create([
                'amount' => $this->minorAmount($payment->amount, $currency),
                'currency' => $currency,
                'description' => $payment->meta['description'] ?? ('Payment for order ' . $payment->orderId),
                'reference_id' => $payment->orderId,
                "callback_url" => $this->callbackUrl(),
                "callback_method" => "get",
            ]);

            return new PaymentResponseData(
                true,
                $this->gatewayName,
                $response['id'] ?? null,
                $response['short_url'] ?? null,
                'pending',
                false,
                'Razorpay payment link generated successfully.',
                $response->toArray()
            );
        } catch (\Exception $e) {
            throw new \Exception("Razorpay Payment Initialization Failed: " . $e->getMessage());
        }
    }

    protected function verifyPayment(PaymentSession $session, Request $request): PaymentResponseData
    {
        $api = new \Razorpay\Api\Api(
            $this->config['public_key'],
            $this->config['secret_key']
        );

        // Fetch the payment link stored at initiation straight from Razorpay.
        $link = $api->paymentLink->fetch((string) $session->payment_id);
        $data = $link->toArray();

        if (($data['status'] ?? null) === 'paid') {
            $paymentId = data_get($data, 'payments.0.payment_id', $session->payment_id);

            return $this->verified($session, is_string($paymentId) ? $paymentId : $session->payment_id, $data, 'Razorpay payment verified.');
        }

        $status = in_array($data['status'] ?? '', ['cancelled', 'expired'], true)
            ? $data['status']
            : 'pending';

        return $this->unverified(
            $session,
            "Razorpay reports payment link status '" . ($data['status'] ?? 'unknown') . "'.",
            $data,
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
