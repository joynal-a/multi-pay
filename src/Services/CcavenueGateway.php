<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Models\PaymentSession;
use Illuminate\Http\Request;

/**
 * CCAvenue hosted checkout (encrypted browser form post).
 * base_url: https://test.ccavenue.com (test) / https://secure.ccavenue.com (live)
 */
class CcavenueGateway extends BaseGateway
{
    public function needyConfig(): array
    {
        return [
            'merchant_id',
            'access_code',
            'working_key',
            'base_url',
        ];
    }

    protected function requestPayment(PaymentRequestData $payment): PaymentResponseData
    {
        $params = [
            'merchant_id' => $this->config['merchant_id'],
            'order_id' => $payment->orderId,
            'amount' => number_format($payment->amount, 2, '.', ''),
            'currency' => strtoupper($payment->currency ?: 'INR'),
            'redirect_url' => $this->successUrl(),
            'cancel_url' => $this->cancelUrl(),
            'language' => 'EN',
            'billing_name' => $payment->customer->name ?? 'Customer',
            'billing_email' => $payment->customer->email ?? '',
            'billing_tel' => (string) ($payment->meta['phone'] ?? ''),
            'merchant_param1' => $this->sessionIdentifier(),
        ];

        $encRequest = $this->encrypt(http_build_query($params));

        return $this->formPostResponse(
            $payment,
            rtrim($this->config['base_url'], '/') . '/transaction/transaction.do?command=initiateTransaction',
            [
                'encRequest' => $encRequest,
                'access_code' => $this->config['access_code'],
            ],
            'CCAvenue'
        );
    }

    protected function verifyPayment(PaymentSession $session, Request $request): PaymentResponseData
    {
        // CCAvenue posts back encResp, AES-encrypted with our working key —
        // only CCAvenue and we hold it, so a clean decrypt is the proof.
        $encResp = (string) $request->input('encResp', '');

        if ($encResp === '') {
            return $this->unverified($session, 'CCAvenue response (encResp) is missing.', [], 'pending');
        }

        try {
            parse_str($this->decrypt($encResp), $data);
        } catch (\Throwable $e) {
            return $this->unverified($session, 'CCAvenue response could not be decrypted: ' . $e->getMessage());
        }

        $orderStatus = (string) ($data['order_status'] ?? '');
        $orderOk = (string) ($data['order_id'] ?? '') === (string) $session->order_id;
        $amountOk = (float) ($data['amount'] ?? 0) >= (float) $session->amount;

        if ($orderStatus === 'Success' && $orderOk && $amountOk) {
            $paymentId = (string) ($data['tracking_id'] ?? $session->payment_id);

            return $this->verified($session, $paymentId, $data, 'CCAvenue payment verified.');
        }

        $mapped = match ($orderStatus) {
            'Aborted' => 'cancelled',
            'Failure' => 'failed',
            default => $orderStatus === 'Success' ? 'failed' : 'pending',
        };

        return $this->unverified(
            $session,
            $orderStatus === 'Success'
                ? 'CCAvenue response order/amount does not match the session.'
                : "CCAvenue reports order status '" . ($orderStatus ?: 'unknown') . "'.",
            $data,
            $mapped
        );
    }

    protected function encrypt(string $plainText): string
    {
        $encrypted = openssl_encrypt(
            $plainText,
            'AES-128-CBC',
            hex2bin(md5($this->config['working_key'])) ?: '',
            OPENSSL_RAW_DATA,
            $this->initVector()
        );

        if ($encrypted === false) {
            throw new \Exception('Unable to encrypt CCAvenue payload.');
        }

        return bin2hex($encrypted);
    }

    protected function decrypt(string $cipherHex): string
    {
        $binary = hex2bin($cipherHex);

        if ($binary === false) {
            throw new \Exception('Invalid CCAvenue response format.');
        }

        $decrypted = openssl_decrypt(
            $binary,
            'AES-128-CBC',
            hex2bin(md5($this->config['working_key'])) ?: '',
            OPENSSL_RAW_DATA,
            $this->initVector()
        );

        if ($decrypted === false) {
            throw new \Exception('Unable to decrypt CCAvenue response.');
        }

        return $decrypted;
    }

    protected function initVector(): string
    {
        return pack('C*', 0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15);
    }
}
