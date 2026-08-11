<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Models\PaymentSession;
use Braintree\Gateway;
use Illuminate\Http\Request;

class BraintreeGateway extends BaseGateway
{
    /**
     * Transaction statuses that mean the money is (or is on its way to being)
     * captured.
     */
    protected const PAID_STATUSES = [
        'authorized',
        'submitted_for_settlement',
        'settling',
        'settlement_pending',
        'settled',
    ];

    /**
     * Required config keys for the gateway
     *
     * @return array
     */
    public function needyConfig(): array
    {
        return [
            'environment',
            'merchant_id',
            'public_key',
            'private_key'
        ];
    }


    /**
     * Initiate a payment with Stripe
     *
     * Expected $payload keys:
     * - amount (int) Required. Amount in smallest currency unit.
     * - email (string) Required. Customer's email address.
     *
     * @param array $payload
     * @return array ['payment_url' => string, 'payment_id' => string]
     * @throws \Exception
     */
    protected function requestPayment(PaymentRequestData $payment): PaymentResponseData
    {
        return new PaymentResponseData(
            true,
            $this->gatewayName,
            (string) $this->paymentSession?->routeIdentifier(),
            \Abedin\MultiPay\Support\AppRoutes::internalUrl('braintree.checkout', (string) $this->paymentSession?->routeIdentifier()),
            'pending',
            true,
            'Braintree checkout page generated successfully.',
            [
                'order_id' => $payment->orderId,
            ]
        );
    }

    protected function verifyPayment(PaymentSession $session, Request $request): PaymentResponseData
    {
        // The transaction id is only stored after captureHostedPayment() ran
        // server-side; a session without one never reached the charge step.
        if (!$session->payment_id) {
            return $this->unverified($session, 'No Braintree transaction was captured for this session.');
        }

        $transaction = $this->gateway()->transaction()->find((string) $session->payment_id);
        $raw = [
            'id' => $transaction->id,
            'status' => $transaction->status,
            'type' => $transaction->type,
            'amount' => (string) $transaction->amount,
        ];

        if (in_array($transaction->status, self::PAID_STATUSES, true)) {
            return $this->verified($session, $transaction->id, $raw, 'Braintree payment verified.');
        }

        return $this->unverified(
            $session,
            "Braintree reports transaction status '{$transaction->status}'.",
            $raw
        );
    }

    public function generateClientToken(): string
    {
        return $this->gateway()->clientToken()->generate();
    }

    public function captureHostedPayment(PaymentSession $session, string $paymentMethodNonce): array
    {
        if ($paymentMethodNonce === '') {
            return [
                'success' => false,
                'message' => 'Payment method nonce is required.',
            ];
        }

        $result = $this->gateway()->transaction()->sale([
            'amount' => number_format((float) $session->amount, 2, '.', ''),
            'orderId' => $session->order_id,
            'paymentMethodNonce' => $paymentMethodNonce,
            'customer' => [
                'firstName' => $this->extractFirstName((string) data_get($session->customer, 'name')),
                'lastName' => $this->extractLastName((string) data_get($session->customer, 'name')),
                'email' => data_get($session->customer, 'email'),
            ],
            'options' => [
                'submitForSettlement' => true,
            ],
        ]);

        if (!$result->success) {
            return [
                'success' => false,
                'message' => $this->collectErrorMessage($result),
            ];
        }

        $transaction = $result->transaction;

        $session->update([
            'payment_id' => $transaction->id,
            'status' => $transaction->status,
            'raw_response' => [
                'id' => $transaction->id,
                'status' => $transaction->status,
                'type' => $transaction->type,
                'processor_response_text' => $transaction->processorResponseText,
            ],
        ]);

        return [
            'success' => true,
            'payment_id' => $transaction->id,
            'message' => 'Braintree payment captured successfully.',
        ];
    }

    protected function gateway(): Gateway
    {
        return new Gateway([
            'environment' => $this->config['environment'],
            'merchantId' => $this->config['merchant_id'],
            'publicKey' => $this->config['public_key'],
            'privateKey' => $this->config['private_key'],
        ]);
    }

    protected function extractFirstName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));

        return $parts[0] ?? 'Customer';
    }

    protected function extractLastName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));

        if (!$parts || count($parts) < 2) {
            return 'Customer';
        }

        array_shift($parts);

        return implode(' ', $parts);
    }

    protected function collectErrorMessage(object $result): string
    {
        if (isset($result->message) && is_string($result->message) && $result->message !== '') {
            return $result->message;
        }

        $messages = [];

        if (isset($result->errors)) {
            foreach ($result->errors->deepAll() as $error) {
                $messages[] = $error->message;
            }
        }

        return $messages !== [] ? implode(' ', $messages) : 'Braintree transaction failed.';
    }
}
