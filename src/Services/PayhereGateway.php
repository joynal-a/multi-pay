<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Models\PaymentSession;
use Illuminate\Http\Request;

/**
 * PayHere (Sri Lanka) hosted checkout.
 * base_url: https://sandbox.payhere.lk (sandbox) / https://www.payhere.lk (live)
 */
class PayhereGateway extends BaseGateway
{
    public function needyConfig(): array
    {
        return [
            'merchant_id',
            'merchant_secret',
            'base_url',
        ];
    }

    protected function requestPayment(PaymentRequestData $payment): PaymentResponseData
    {
        $amount = number_format($payment->amount, 2, '.', '');
        $currency = strtoupper($payment->currency ?: 'LKR');
        $name = trim((string) ($payment->customer->name ?? 'Customer'));
        $parts = preg_split('/\s+/', $name) ?: ['Customer'];

        $hash = strtoupper(md5(
            $this->config['merchant_id']
            . $payment->orderId
            . $amount
            . $currency
            . strtoupper(md5($this->config['merchant_secret']))
        ));

        return $this->formPostResponse(
            $payment,
            rtrim($this->config['base_url'], '/') . '/pay/checkout',
            [
                'merchant_id' => $this->config['merchant_id'],
                'return_url' => $this->successUrl(),
                'cancel_url' => $this->cancelUrl(),
                'notify_url' => $this->callbackUrl(),
                'order_id' => $payment->orderId,
                'items' => (string) ($payment->meta['title'] ?? ('Order ' . $payment->orderId)),
                'currency' => $currency,
                'amount' => $amount,
                'first_name' => $parts[0] ?? 'Customer',
                'last_name' => count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '-',
                'email' => $payment->customer->email ?? 'customer@example.com',
                'phone' => (string) ($payment->meta['phone'] ?? '0000000000'),
                'address' => (string) ($payment->meta['address'] ?? 'Not Available'),
                'city' => (string) ($payment->meta['city'] ?? 'Colombo'),
                'country' => (string) ($payment->meta['country'] ?? 'Sri Lanka'),
                'hash' => $hash,
            ],
            'PayHere'
        );
    }

    protected function verifyPayment(PaymentSession $session, Request $request): PaymentResponseData
    {
        // PayHere's server-to-server notify carries md5sig signed with our
        // merchant secret. The browser return_url carries nothing verifiable,
        // so real confirmation happens on the callback (notify) route.
        $md5sig = strtoupper((string) $request->input('md5sig', ''));

        if ($md5sig === '') {
            return $this->unverified(
                $session,
                'No PayHere signature in this request — confirmation arrives on the notify (callback) URL.',
                [],
                'pending'
            );
        }

        $statusCode = (string) $request->input('status_code', '');
        $payhereAmount = (string) $request->input('payhere_amount', '');
        $payhereCurrency = (string) $request->input('payhere_currency', '');
        $orderId = (string) $request->input('order_id', '');

        $localSig = strtoupper(md5(
            $this->config['merchant_id']
            . $orderId
            . $payhereAmount
            . $payhereCurrency
            . $statusCode
            . strtoupper(md5($this->config['merchant_secret']))
        ));

        $raw = $request->all();

        if (!hash_equals($localSig, $md5sig)) {
            return $this->unverified($session, 'PayHere signature does not match — response rejected.', $raw);
        }

        $orderOk = $orderId === (string) $session->order_id;
        $amountOk = (float) $payhereAmount >= (float) $session->amount;

        if ($statusCode === '2' && $orderOk && $amountOk) {
            $paymentId = (string) $request->input('payment_id', $session->payment_id);

            return $this->verified($session, $paymentId, $raw, 'PayHere payment verified.');
        }

        $mapped = match ($statusCode) {
            '0' => 'pending',
            '-1' => 'cancelled',
            default => 'failed',
        };

        return $this->unverified(
            $session,
            $statusCode === '2'
                ? 'PayHere response order/amount does not match the session.'
                : "PayHere reports status code '{$statusCode}'.",
            $raw,
            $mapped
        );
    }
}
