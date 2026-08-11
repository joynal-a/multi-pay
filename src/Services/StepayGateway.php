<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Models\PaymentSession;
use Illuminate\Http\Request;

/**
 * StePay scaffold. StePay (getstepay.com) publishes no public API
 * documentation — merchants receive integration docs after signup. Wire the
 * real contract in here once those docs are available.
 */
class StepayGateway extends BaseGateway
{
    public function needyConfig(): array
    {
        return [
            'merchant_id',
            'api_key',
            'base_url',
        ];
    }

    protected function requestPayment(PaymentRequestData $payment): PaymentResponseData
    {
        throw new \RuntimeException(
            'StePay gateway scaffold is registered, but no public API documentation exists to implement it. ' .
            'Request merchant API docs from StePay and implement requestPayment()/verifyPayment() against them.'
        );
    }

    protected function verifyPayment(PaymentSession $session, Request $request): PaymentResponseData
    {
        throw new \RuntimeException(
            'StePay gateway scaffold cannot verify payments yet — no public API documentation exists.'
        );
    }
}
