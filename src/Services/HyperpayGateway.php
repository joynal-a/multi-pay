<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Models\PaymentSession;
use Abedin\MultiPay\Support\AppRoutes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * HyperPay (OPPWA COPYandPAY widget).
 * base_url: https://eu-test.oppwa.com (test) / https://eu-prod.oppwa.com (live)
 */
class HyperpayGateway extends BaseGateway
{
    /**
     * OPPWA success result codes: transaction succeeded / pending review.
     */
    protected const SUCCESS_PATTERN = '/^(000\.000\.|000\.100\.1|000\.[36])/';

    public function needyConfig(): array
    {
        return [
            'access_token',
            'entity_id',
            'base_url',
        ];
    }

    protected function requestPayment(PaymentRequestData $payment): PaymentResponseData
    {
        try {
            $response = Http::withToken($this->config['access_token'])
                ->asForm()
                ->post(rtrim($this->config['base_url'], '/') . '/v1/checkouts', [
                    'entityId' => $this->config['entity_id'],
                    'amount' => number_format($payment->amount, 2, '.', ''),
                    'currency' => strtoupper($payment->currency ?: 'SAR'),
                    'paymentType' => 'DB',
                    'merchantTransactionId' => $payment->orderId,
                ]);

            $json = (array) $response->json();
            $checkoutId = (string) ($json['id'] ?? '');

            if (!$response->successful() || $checkoutId === '') {
                throw new \Exception((string) data_get($json, 'result.description', json_encode($json)));
            }

            return new PaymentResponseData(
                true,
                $this->gatewayName,
                $checkoutId,
                AppRoutes::internalUrl('hyperpay.checkout', $this->sessionIdentifier()),
                'pending',
                true,
                'HyperPay checkout generated successfully.',
                $json
            );
        } catch (\Throwable $e) {
            throw new \Exception('HyperPay Payment Initialization Failed: ' . $e->getMessage());
        }
    }

    protected function verifyPayment(PaymentSession $session, Request $request): PaymentResponseData
    {
        // Query the checkout's payment status server-side.
        $response = Http::withToken($this->config['access_token'])->get(
            rtrim($this->config['base_url'], '/') . '/v1/checkouts/' . urlencode((string) $session->payment_id) . '/payment',
            ['entityId' => $this->config['entity_id']]
        );

        $json = (array) $response->json();
        $code = (string) data_get($json, 'result.code', '');

        if ($response->successful() && preg_match(self::SUCCESS_PATTERN, $code)) {
            $paymentId = (string) ($json['id'] ?? $session->payment_id);

            return $this->verified($session, $paymentId, $json, 'HyperPay payment verified.');
        }

        return $this->unverified(
            $session,
            'HyperPay reports: ' . ((string) data_get($json, 'result.description', "result code '{$code}'")),
            $json,
            preg_match('/^(000\.200)/', $code) ? 'pending' : 'failed'
        );
    }

    public function widgetScriptUrl(PaymentSession $session): string
    {
        return rtrim($this->config['base_url'], '/') . '/v1/paymentWidgets.js?checkoutId=' . urlencode((string) $session->payment_id);
    }

    public function paymentBrands(): string
    {
        return (string) ($this->rawConfig['brands'] ?? 'VISA MASTER MADA');
    }
}
