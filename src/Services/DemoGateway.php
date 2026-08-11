<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Models\PaymentSession;
use Illuminate\Http\Request;

/**
 * Fake gateway for local testing: needs no credentials, "pays" every
 * session. Its payment URL points straight at the app's success route so the
 * whole pay → redirect → confirm loop can be exercised end to end.
 */
class DemoGateway extends BaseGateway
{
    public function needyConfig(): array
    {
        return [];
    }

    protected function requestPayment(PaymentRequestData $payment): PaymentResponseData
    {
        return new PaymentResponseData(
            true,
            $this->gatewayName,
            'demo-' . $this->sessionIdentifier(),
            $this->successUrl(),
            'pending',
            true,
            'Demo gateway response generated successfully.',
            [
                'order_id' => $payment->orderId,
            ]
        );
    }

    protected function verifyPayment(PaymentSession $session, Request $request): PaymentResponseData
    {
        return $this->verified($session, $session->payment_id, [
            'demo' => true,
            'order_id' => $session->order_id,
        ], 'Demo payment verified (always succeeds — testing only).');
    }
}
