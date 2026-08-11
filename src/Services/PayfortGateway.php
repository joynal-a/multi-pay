<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Models\PaymentSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Amazon Payment Services (PayFort) redirect integration.
 * checkout_base_url: https://sbcheckout.payfort.com (sandbox) / https://checkout.payfort.com (live)
 * base_url (API):    https://sbpaymentservices.payfort.com (sandbox) / https://paymentservices.payfort.com (live)
 */
class PayfortGateway extends BaseGateway
{
    public function needyConfig(): array
    {
        return [
            'access_code',
            'merchant_identifier',
            'sha_request_phrase',
            'base_url',
            'checkout_base_url',
        ];
    }

    protected function requestPayment(PaymentRequestData $payment): PaymentResponseData
    {
        $currency = strtoupper($payment->currency ?: 'AED');

        $fields = [
            'command' => 'PURCHASE',
            'access_code' => $this->config['access_code'],
            'merchant_identifier' => $this->config['merchant_identifier'],
            'merchant_reference' => $payment->orderId,
            'amount' => (string) $this->minorAmount($payment->amount, $currency),
            'currency' => $currency,
            'language' => 'en',
            'customer_email' => $payment->customer->email ?? 'customer@example.com',
            'return_url' => $this->callbackUrl(),
        ];

        $fields['signature'] = $this->signature($fields, $this->config['sha_request_phrase']);

        return $this->formPostResponse(
            $payment,
            rtrim($this->config['checkout_base_url'], '/') . '/FortAPI/paymentPage',
            $fields,
            'PayFort'
        );
    }

    protected function verifyPayment(PaymentSession $session, Request $request): PaymentResponseData
    {
        // CHECK_STATUS API by our merchant reference.
        $fields = [
            'query_command' => 'CHECK_STATUS',
            'access_code' => $this->config['access_code'],
            'merchant_identifier' => $this->config['merchant_identifier'],
            'merchant_reference' => (string) $session->order_id,
            'language' => 'en',
        ];
        $fields['signature'] = $this->signature($fields, $this->config['sha_request_phrase']);

        $response = Http::acceptJson()
            ->post(rtrim($this->config['base_url'], '/') . '/FortAPI/paymentApi', $fields);

        $json = (array) $response->json();
        $transactionStatus = (string) ($json['transaction_status'] ?? '');

        // 14 = purchase success
        if ($response->successful() && $transactionStatus === '14') {
            $paymentId = (string) ($json['fort_id'] ?? $session->payment_id);

            return $this->verified($session, $paymentId, $json, 'PayFort payment verified.');
        }

        return $this->unverified(
            $session,
            'PayFort reports: ' . ((string) ($json['transaction_message'] ?? $json['response_message'] ?? "transaction_status '{$transactionStatus}'")),
            $json,
            $transactionStatus === '19' ? 'pending' : 'failed'
        );
    }

    /**
     * APS signature: sha256(phrase + sorted key=value pairs + phrase).
     */
    protected function signature(array $fields, string $phrase): string
    {
        ksort($fields);
        $joined = '';

        foreach ($fields as $key => $value) {
            $joined .= $key . '=' . $value;
        }

        return hash('sha256', $phrase . $joined . $phrase);
    }
}
