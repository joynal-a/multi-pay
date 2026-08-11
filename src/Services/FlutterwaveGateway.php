<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Models\PaymentSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class FlutterwaveGateway extends BaseGateway
{
    public function needyConfig(): array
    {
        return [
            'secret_key',
            'base_url',
        ];
    }

    protected function requestPayment(PaymentRequestData $payment): PaymentResponseData
    {
        $description = $payment->meta['description'] ?? ('Payment for order ' . $payment->orderId);

        $payload = [
            'tx_ref' => $payment->orderId,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'redirect_url' => $this->successUrl(),
            'customer' => [
                'email' => $payment->customer->email ?? '[email protected]',
                'name' => $payment->customer->name ?? 'Customer',
                'phonenumber' => (string) ($payment->meta['phone'] ?? '9999999999'),
            ],
            'customizations' => [
                'title' => $payment->meta['title'] ?? 'Flutterwave Payment',
                'description' => $description,
                'logo' => $payment->meta['logo'] ?? null,
            ],
            'meta' => [
                'order_id' => $payment->orderId,
                'payment_session_id' => $this->sessionIdentifier(),
            ],
        ];

        if (!empty($payment->meta['payment_options'])) {
            $payload['payment_options'] = $payment->meta['payment_options'];
        }

        if (!empty($payment->meta['session_duration']) || !empty($payment->meta['max_retry_attempt'])) {
            $payload['configurations'] = array_filter([
                'session_duration' => $payment->meta['session_duration'] ?? null,
                'max_retry_attempt' => $payment->meta['max_retry_attempt'] ?? null,
            ], static fn ($value) => $value !== null && $value !== '');
        }

        $payload['customizations'] = array_filter(
            $payload['customizations'],
            static fn ($value) => $value !== null && $value !== ''
        );

        try {
            $response = Http::withToken($this->config['secret_key'])
                ->acceptJson()
                ->post(rtrim($this->config['base_url'], '/') . '/v3/payments', $payload);

            $json = $response->json();

            if (!$response->successful()) {
                throw new \Exception(is_array($json) ? json_encode($json) : $response->body());
            }

            return new PaymentResponseData(
                true,
                $this->gatewayName,
                $payment->orderId,
                $json['data']['link'] ?? null,
                'pending',
                false,
                'Flutterwave checkout URL generated successfully.',
                is_array($json) ? $json : []
            );
        } catch (\Throwable $e) {
            throw new \Exception('Flutterwave Payment Initialization Failed: ' . $e->getMessage());
        }
    }

    protected function verifyPayment(PaymentSession $session, Request $request): PaymentResponseData
    {
        // tx_ref was set to our order id at initiation — ask Flutterwave for
        // the transaction behind it and match status, amount, and currency.
        $response = Http::withToken($this->config['secret_key'])
            ->acceptJson()
            ->get(rtrim($this->config['base_url'], '/') . '/v3/transactions/verify_by_reference', [
                'tx_ref' => (string) $session->order_id,
            ]);

        $json = (array) $response->json();
        $data = (array) ($json['data'] ?? []);
        $status = (string) ($data['status'] ?? '');
        $amountOk = (float) ($data['amount'] ?? 0) >= (float) $session->amount;
        $currencyOk = strtoupper((string) ($data['currency'] ?? '')) === strtoupper((string) $session->currency);

        if ($response->successful() && $status === 'successful' && $amountOk && $currencyOk) {
            $paymentId = isset($data['id']) ? (string) $data['id'] : $session->payment_id;

            return $this->verified($session, $paymentId, $json, 'Flutterwave payment verified.');
        }

        $message = $status === 'successful'
            ? 'Flutterwave transaction amount/currency does not match the session.'
            : "Flutterwave reports transaction status '" . ($status ?: 'unknown') . "'.";

        return $this->unverified($session, $message, $json);
    }
}
