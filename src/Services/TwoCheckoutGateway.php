<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Models\PaymentSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * 2Checkout (Verifone) ConvertPlus hosted buy-link + REST 6.0 verification.
 */
class TwoCheckoutGateway extends BaseGateway
{
    protected const BUY_URL = 'https://secure.2checkout.com/checkout/buy';
    protected const API_URL = 'https://api.2checkout.com/rest/6.0';

    public function needyConfig(): array
    {
        return [
            'merchant_code',
            'secret_key',
            'buy_link_secret_word',
        ];
    }

    protected function requestPayment(PaymentRequestData $payment): PaymentResponseData
    {
        $params = [
            'merchant' => $this->config['merchant_code'],
            'dynamic' => '1',
            'currency' => strtolower($payment->currency ?: 'USD'),
            'prod' => (string) ($payment->meta['title'] ?? ('Order ' . $payment->orderId)),
            'price' => number_format($payment->amount, 2, '.', ''),
            'qty' => '1',
            'type' => 'digital',
            'tangible' => '0',
            'order-ext-ref' => $payment->orderId,
            'customer-ext-ref' => $payment->customer->email ?? $payment->orderId,
            'email' => $payment->customer->email ?? '',
            'return-method' => 'redirect',
            'return-url' => $this->successUrl(),
        ];

        $params = array_filter($params, static fn ($value) => $value !== '');
        $params['signature'] = $this->buyLinkSignature($params);

        return new PaymentResponseData(
            true,
            $this->gatewayName,
            $payment->orderId,
            self::BUY_URL . '?' . http_build_query($params),
            'pending',
            false,
            '2Checkout buy link generated successfully.',
            ['buy_link_params' => array_diff_key($params, ['signature' => true])]
        );
    }

    protected function verifyPayment(PaymentSession $session, Request $request): PaymentResponseData
    {
        // Look the order up by our external reference via REST 6.0.
        $date = gmdate('Y-m-d H:i:s');
        $code = $this->config['merchant_code'];
        $hash = hash_hmac(
            'sha256',
            strlen($code) . $code . strlen($date) . $date,
            $this->config['secret_key']
        );

        $response = Http::withHeaders([
            'X-Avangate-Authentication' => sprintf('code="%s" date="%s" hash="%s" algo="sha256"', $code, $date, $hash),
            'Accept' => 'application/json',
        ])->get(self::API_URL . '/orders/', [
            'ExternalReference' => (string) $session->order_id,
            'Limit' => 5,
        ]);

        $json = (array) $response->json();
        $orders = (array) ($json['Items'] ?? $json);

        foreach ($orders as $order) {
            if (!is_array($order)) {
                continue;
            }

            $status = strtoupper((string) ($order['Status'] ?? ''));

            if ($status === 'COMPLETE') {
                $paymentId = (string) ($order['RefNo'] ?? $session->payment_id);

                return $this->verified($session, $paymentId, $order, '2Checkout payment verified.');
            }
        }

        $latestStatus = '';
        foreach ($orders as $order) {
            if (is_array($order) && isset($order['Status'])) {
                $latestStatus = (string) $order['Status'];
                break;
            }
        }

        return $this->unverified(
            $session,
            $latestStatus === ''
                ? '2Checkout has no order for this reference yet.'
                : "2Checkout reports order status '{$latestStatus}'.",
            $json,
            in_array(strtoupper($latestStatus), ['AUTHRECEIVED', 'PENDING', ''], true) ? 'pending' : 'failed'
        );
    }

    /**
     * ConvertPlus dynamic-product signature: HMAC-SHA256 over
     * len(value).value of the sorted params, keyed by the buy-link secret word.
     */
    protected function buyLinkSignature(array $params): string
    {
        ksort($params);
        $serialized = '';

        foreach ($params as $value) {
            $serialized .= strlen((string) $value) . $value;
        }

        return hash_hmac('sha256', $serialized, $this->config['buy_link_secret_word']);
    }
}
