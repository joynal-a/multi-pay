<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Models\PaymentSession;
use Adyen\Service\Checkout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AdyenGateway extends BaseGateway
{
    public function needyConfig(): array
    {
        return [
            'api_key',
            'merchant_account',
            'country_code'
        ];
    }

    protected function requestPayment(PaymentRequestData $payment): PaymentResponseData
    {
        $config = $this->config;

        try {
            $currency = $payment->currency ?: 'EUR';
            $params = [
                "amount" => [
                    "currency" => $currency,
                    "value"    => $this->minorAmount($payment->amount, $currency)
                ],
                "reference" => $payment->orderId,
                "merchantAccount" => $config['merchant_account'],
                "returnUrl" => $this->callbackUrl(),
                "countryCode" => $config['country_code'],
            ];

            $client = new \Adyen\Client();
            $client->setXApiKey($config['api_key']);
            $client->setEnvironment(\Adyen\Environment::TEST);

            $checkout = new Checkout($client);
            // Generate payment link
            $response = $checkout->paymentLinks($params);

            return new PaymentResponseData(
                true,
                $this->gatewayName,
                $response['id'] ?? null,
                $response['url'] ?? null,
                'pending',
                false,
                'Adyen payment link generated successfully.',
                $response
            );
        } catch (\Throwable $e) {
            throw new \Exception("Adyen Payment Initialization Failed: " . $e->getMessage());
        }
    }

    protected function verifyPayment(PaymentSession $session, Request $request): PaymentResponseData
    {
        // Query the payment link stored at initiation. "completed" means the
        // shopper paid; anything else is not money in the account.
        $baseUrl = rtrim((string) ($this->rawConfig['checkout_api_base_url'] ?? 'https://checkout-test.adyen.com/v70'), '/');

        $response = Http::withHeaders([
            'X-API-Key' => $this->config['api_key'],
            'Accept' => 'application/json',
        ])->get($baseUrl . '/paymentLinks/' . urlencode((string) $session->payment_id));

        $json = (array) $response->json();
        $status = (string) ($json['status'] ?? '');

        if ($response->successful() && $status === 'completed') {
            return $this->verified($session, $session->payment_id, $json, 'Adyen payment verified.');
        }

        return $this->unverified(
            $session,
            "Adyen reports payment link status '" . ($status ?: 'unknown') . "'.",
            $json,
            $status === 'expired' ? 'expired' : 'failed'
        );
    }
}
