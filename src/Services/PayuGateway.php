<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Models\PaymentSession;
use Illuminate\Http\Request;

class PayuGateway extends BaseGateway
{
    public function needyConfig(): array
    {
        return [
            'merchant_key',
            'salt',
            'base_url',
        ];
    }

    protected function requestPayment(PaymentRequestData $payment): PaymentResponseData
    {
        $sessionId = $this->paymentSession?->routeIdentifier();

        return new PaymentResponseData(
            true,
            $this->gatewayName,
            $payment->orderId,
            \Abedin\MultiPay\Support\AppRoutes::internalUrl('payu.checkout', (string) $sessionId),
            'pending',
            true,
            'PayU checkout page generated successfully.',
            [
                'txnid' => $payment->orderId,
                'session_id' => $sessionId,
            ]
        );
    }

    protected function verifyPayment(PaymentSession $session, Request $request): PaymentResponseData
    {
        // PayU posts the result back with a response hash signed by our salt.
        // Recomputing it proves the fields were not tampered with.
        $status = (string) $request->input('status', '');
        $receivedHash = strtolower((string) $request->input('hash', ''));

        if ($status === '' || $receivedHash === '') {
            return $this->unverified($session, 'PayU response is missing status or hash.', [], 'pending');
        }

        $fields = [
            'txnid' => (string) $request->input('txnid', ''),
            'amount' => (string) $request->input('amount', ''),
            'productinfo' => (string) $request->input('productinfo', ''),
            'firstname' => (string) $request->input('firstname', ''),
            'email' => (string) $request->input('email', ''),
        ];

        $expectedHash = hash('sha512', implode('|', [
            $this->config['salt'],
            $status,
            '', '', '', '', '',          // reserved
            '', '', '', '', '',          // udf5..udf1 (unused)
            $fields['email'],
            $fields['firstname'],
            $fields['productinfo'],
            $fields['amount'],
            $fields['txnid'],
            $this->config['merchant_key'],
        ]));

        $raw = $request->all();

        if (!hash_equals($expectedHash, $receivedHash)) {
            return $this->unverified($session, 'PayU response hash does not match — response rejected.', $raw);
        }

        $txnOk = $fields['txnid'] === (string) $session->order_id;
        $amountOk = (float) $fields['amount'] >= (float) $session->amount;

        if ($status === 'success' && $txnOk && $amountOk) {
            $paymentId = (string) $request->input('mihpayid', $session->payment_id);

            return $this->verified($session, $paymentId, $raw, 'PayU payment verified.');
        }

        return $this->unverified(
            $session,
            $status === 'success'
                ? 'PayU response txnid/amount does not match the session.'
                : "PayU reports status '{$status}'.",
            $raw
        );
    }

    public function paymentEndpoint(): string
    {
        return rtrim($this->config['base_url'], '/') . '/_payment';
    }

    public function buildHostedCheckoutFields(PaymentSession $session): array
    {
        $firstname = $this->customerName($session);
        $email = $this->customerEmail($session);
        $amount = number_format((float) $session->amount, 2, '.', '');
        $productInfo = data_get($session->meta, 'description', 'Payment for order ' . $session->order_id);
        $phone = (string) data_get($session->meta, 'phone', '9999999999');

        return [
            'key' => $this->config['merchant_key'],
            'txnid' => (string) $session->order_id,
            'amount' => $amount,
            'firstname' => $firstname,
            'email' => $email,
            'phone' => $phone,
            'productinfo' => $productInfo,
            'surl' => $this->successRouteForSession($session),
            'furl' => $this->failureRouteForSession($session),
            'hash' => $this->generateHash(
                merchantKey: $this->config['merchant_key'],
                txnId: (string) $session->order_id,
                amount: $amount,
                productInfo: $productInfo,
                firstname: $firstname,
                email: $email,
                salt: $this->config['salt'],
            ),
        ];
    }

    protected function generateHash(
        string $merchantKey,
        string $txnId,
        string $amount,
        string $productInfo,
        string $firstname,
        string $email,
        string $salt
    ): string {
        $hashString = implode('|', [
            $merchantKey,
            $txnId,
            $amount,
            $productInfo,
            $firstname,
            $email,
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            $salt,
        ]);

        return hash('sha512', $hashString);
    }

    protected function customerName(PaymentSession $session): string
    {
        return (string) data_get($session->customer, 'name', 'Customer');
    }

    protected function customerEmail(PaymentSession $session): string
    {
        return (string) data_get($session->customer, 'email', '[email protected]');
    }

    protected function successRouteForSession(PaymentSession $session): string
    {
        return \Abedin\MultiPay\Support\AppRoutes::url('success', $session->routeIdentifier());
    }

    protected function failureRouteForSession(PaymentSession $session): string
    {
        return \Abedin\MultiPay\Support\AppRoutes::url('failure', $session->routeIdentifier());
    }
}
