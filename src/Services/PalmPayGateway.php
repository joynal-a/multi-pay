<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;

class PalmPayGateway extends BaseGateway
{
    protected function verifyPayment(\Abedin\MultiPay\Models\PaymentSession $session, \Illuminate\Http\Request $request): PaymentResponseData
    {
        throw new \RuntimeException(
            'PalmPay gateway scaffold cannot verify payments yet — the create/query-order implementation is not complete.'
        );
    }

    public function needyConfig(): array
    {
        return [
            'merchant_id',
            'app_id',
            'private_key',
            'public_key',
            'base_url',
        ];
    }

    protected function requestPayment(PaymentRequestData $payment): PaymentResponseData
    {
        throw new \RuntimeException(
            'PalmPay gateway scaffold is registered, but the live create-order implementation is not complete yet. ' .
            'PalmPay public docs confirm a Checkout flow with create-order, query-order, notification URL, interface domains, and key-based signatures, ' .
            'but the exact request/response contract needed for a trustworthy redirect adapter is not fully exposed in the crawlable public docs used here.'
        );
    }
}
