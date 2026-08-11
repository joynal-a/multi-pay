<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Models\PaymentSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * VoguePay (Nigeria) hosted payment link.
 */
class VoguepayGateway extends BaseGateway
{
    protected const BASE_URL = 'https://pay.voguepay.com';

    public function needyConfig(): array
    {
        return [
            'merchant_id',
        ];
    }

    protected function requestPayment(PaymentRequestData $payment): PaymentResponseData
    {
        $params = [
            'v_merchant_id' => $this->config['merchant_id'],
            'memo' => $payment->meta['description'] ?? ('Payment for order ' . $payment->orderId),
            'total' => number_format($payment->amount, 2, '.', ''),
            'merchant_ref' => $payment->orderId,
            'cur' => strtoupper($payment->currency ?: 'NGN'),
            'notify_url' => $this->callbackUrl(),
            'success_url' => $this->successUrl(),
            'fail_url' => $this->failureUrl(),
            'developer_code' => (string) ($this->rawConfig['developer_code'] ?? ''),
        ];

        if (($this->rawConfig['demo'] ?? false)) {
            $params['demo'] = 'true';
        }

        $params = array_filter($params, static fn ($value) => $value !== '');

        return new PaymentResponseData(
            true,
            $this->gatewayName,
            $payment->orderId,
            self::BASE_URL . '/?' . http_build_query($params),
            'pending',
            false,
            'VoguePay payment link generated successfully.',
            ['link_params' => $params]
        );
    }

    protected function verifyPayment(PaymentSession $session, Request $request): PaymentResponseData
    {
        // VoguePay returns transaction_id on redirect/notify; the proof is
        // querying that transaction server-side from VoguePay itself.
        $transactionId = (string) $request->input('transaction_id', '');

        if ($transactionId === '') {
            return $this->unverified($session, 'VoguePay transaction_id is missing from the response.', [], 'pending');
        }

        $query = ['v_transaction_id' => $transactionId, 'type' => 'json'];

        if (($this->rawConfig['demo'] ?? false)) {
            $query['demo'] = 'true';
        }

        $response = Http::acceptJson()->get(self::BASE_URL . '/', $query);
        $json = (array) $response->json();

        $status = strtolower((string) ($json['status'] ?? ''));
        $refOk = (string) ($json['merchant_ref'] ?? '') === (string) $session->order_id;
        $amountOk = (float) ($json['total'] ?? 0) >= (float) $session->amount;

        if ($response->successful() && $status === 'approved' && $refOk && $amountOk) {
            return $this->verified($session, $transactionId, $json, 'VoguePay payment verified.');
        }

        return $this->unverified(
            $session,
            $status === 'approved'
                ? 'VoguePay transaction reference/amount does not match the session.'
                : "VoguePay reports transaction status '" . ($status ?: 'unknown') . "'.",
            $json
        );
    }
}
