<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;

class OnafriqGateway extends BaseGateway
{
    protected function verifyPayment(\Abedin\MultiPay\Models\PaymentSession $session, \Illuminate\Http\Request $request): PaymentResponseData
    {
        throw new \RuntimeException(
            'Onafriq gateway scaffold cannot verify payments yet — the collections implementation is not complete.'
        );
    }

    public function needyConfig(): array
    {
        return [
            'api_key',
            'api_secret',
            'base_url',
            'channel',
        ];
    }

    protected function requestPayment(PaymentRequestData $payment): PaymentResponseData
    {
        throw new \RuntimeException(
            'Onafriq gateway scaffold is registered, but the live collections implementation is not complete yet. ' .
            'Onafriq public docs confirm collections APIs, mobile collections, card collections, webhook notifications, and a developer portal, ' .
            'but the exact authenticated request-response contract required to create a customer-facing payment URL is not exposed in the publicly crawlable documentation used here.'
        );
    }
}
