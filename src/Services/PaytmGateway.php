<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Models\PaymentSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Paytm (India) — initiateTransaction + hosted payment page.
 * base_url: https://securegw-stage.paytm.in (stage) / https://securegw.paytm.in (live)
 */
class PaytmGateway extends BaseGateway
{
    protected const CHECKSUM_IV = '@@@@&&&&####$$$$';

    public function needyConfig(): array
    {
        return [
            'merchant_id',
            'merchant_key',
            'website',
            'base_url',
        ];
    }

    protected function requestPayment(PaymentRequestData $payment): PaymentResponseData
    {
        $body = [
            'requestType' => 'Payment',
            'mid' => $this->config['merchant_id'],
            'websiteName' => $this->config['website'],
            'orderId' => $payment->orderId,
            'callbackUrl' => $this->callbackUrl(),
            'txnAmount' => [
                'value' => number_format($payment->amount, 2, '.', ''),
                'currency' => strtoupper($payment->currency ?: 'INR'),
            ],
            'userInfo' => [
                'custId' => $payment->customer->email ?? ('CUST-' . $payment->orderId),
            ],
        ];

        try {
            $bodyJson = json_encode($body, JSON_UNESCAPED_SLASHES) ?: '{}';
            $signature = $this->generateSignature($bodyJson);

            $response = Http::acceptJson()->post(
                rtrim($this->config['base_url'], '/') . '/theia/api/v1/initiateTransaction?mid='
                    . urlencode($this->config['merchant_id']) . '&orderId=' . urlencode($payment->orderId),
                ['body' => $body, 'head' => ['signature' => $signature]]
            );

            $json = (array) $response->json();
            $resultStatus = (string) data_get($json, 'body.resultInfo.resultStatus', '');
            $txnToken = (string) data_get($json, 'body.txnToken', '');

            if (!$response->successful() || $resultStatus !== 'S' || $txnToken === '') {
                throw new \Exception((string) data_get($json, 'body.resultInfo.resultMsg', json_encode($json)));
            }

            return $this->formPostResponse(
                $payment,
                rtrim($this->config['base_url'], '/') . '/theia/api/v1/showPaymentPage?mid='
                    . urlencode($this->config['merchant_id']) . '&orderId=' . urlencode($payment->orderId),
                [
                    'mid' => $this->config['merchant_id'],
                    'orderId' => $payment->orderId,
                    'txnToken' => $txnToken,
                ],
                'Paytm',
                $payment->orderId,
                ['initiate_response' => $json]
            );
        } catch (\Throwable $e) {
            throw new \Exception('Paytm Payment Initialization Failed: ' . $e->getMessage());
        }
    }

    protected function verifyPayment(PaymentSession $session, Request $request): PaymentResponseData
    {
        // Transaction Status API by our order id — never trust the callback body.
        $body = [
            'mid' => $this->config['merchant_id'],
            'orderId' => (string) $session->order_id,
        ];
        $bodyJson = json_encode($body, JSON_UNESCAPED_SLASHES) ?: '{}';

        $response = Http::acceptJson()->post(
            rtrim($this->config['base_url'], '/') . '/v3/order/status',
            ['body' => $body, 'head' => ['signature' => $this->generateSignature($bodyJson)]]
        );

        $json = (array) $response->json();
        $resultStatus = (string) data_get($json, 'body.resultInfo.resultStatus', '');
        $amountOk = (float) data_get($json, 'body.txnAmount', 0) >= (float) $session->amount;

        if ($response->successful() && $resultStatus === 'TXN_SUCCESS' && $amountOk) {
            $paymentId = (string) data_get($json, 'body.txnId', $session->payment_id);

            return $this->verified($session, $paymentId, $json, 'Paytm payment verified.');
        }

        return $this->unverified(
            $session,
            "Paytm reports transaction status '" . ($resultStatus ?: 'unknown') . "'.",
            $json,
            $resultStatus === 'PENDING' ? 'pending' : 'failed'
        );
    }

    /**
     * PaytmChecksum signature: base64(AES-128-CBC(sha256(body|salt) . salt)).
     */
    protected function generateSignature(string $body): string
    {
        $salt = substr(bin2hex(random_bytes(4)), 0, 4);
        $hashString = hash('sha256', $body . '|' . $salt) . $salt;

        $encrypted = openssl_encrypt(
            $hashString,
            'AES-128-CBC',
            $this->config['merchant_key'],
            0,
            self::CHECKSUM_IV
        );

        if ($encrypted === false) {
            throw new \Exception('Unable to generate Paytm checksum.');
        }

        return $encrypted;
    }
}
