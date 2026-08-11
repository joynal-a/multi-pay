<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Models\PaymentSession;
use Illuminate\Http\Request;
use Yabacon\Paystack;
use Yabacon\Paystack\Exception\ApiException;

class PaystackGateway extends BaseGateway
{
    protected function requestPayment(PaymentRequestData $payment): PaymentResponseData
    {
        $config = $this->config;

        $paystack = new Paystack($config['secret_key']);
        try{
            $tranx = $paystack->transaction->initialize([
                'amount'=> $this->minorAmount($payment->amount, $payment->currency ?: 'NGN'),
                'email'=> $payment->customer->email,
                'reference' => $payment->orderId,
                'callback_url' => $this->callbackUrl(),
            ]);

            return new PaymentResponseData(
                true,
                $this->gatewayName,
                $tranx->data->reference ?? null,
                $tranx->data->authorization_url ?? null,
                'pending',
                false,
                'Paystack payment link generated successfully.',
                (array) $tranx->data
            );
        } catch (ApiException $e) {
            throw new \Exception("Paystack Payment Initialization Failed: " . $e->getMessage());
        }
    }

    protected function verifyPayment(PaymentSession $session, Request $request): PaymentResponseData
    {
        $paystack = new Paystack($this->config['secret_key']);

        // Verify by the reference stored at initiation, never by request data.
        $tranx = $paystack->transaction->verify([
            'reference' => (string) $session->payment_id,
        ]);

        $data = (array) ($tranx->data ?? []);
        $status = (string) ($data['status'] ?? '');
        $expectedMinor = $this->minorAmount((float) $session->amount, (string) $session->currency);

        if ($status === 'success' && (int) ($data['amount'] ?? 0) >= $expectedMinor) {
            return $this->verified($session, $session->payment_id, $data, 'Paystack payment verified.');
        }

        return $this->unverified(
            $session,
            "Paystack reports transaction status '{$status}'.",
            $data,
            $status === 'abandoned' ? 'cancelled' : 'failed'
        );
    }

    public function needyConfig(): array
    {
        return [
            'secret_key',
        ];
    }
}
