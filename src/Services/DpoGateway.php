<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Models\PaymentSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class DpoGateway extends BaseGateway
{
    public function needyConfig(): array
    {
        return [
            'company_token',
            'base_url',
            'checkout_base_url',
            'service_type',
        ];
    }

    protected function requestPayment(PaymentRequestData $payment): PaymentResponseData
    {
        $description = $payment->meta['description'] ?? ('Payment for order ' . $payment->orderId);
        $serviceDate = $payment->meta['service_date'] ?? now()->format('Y/m/d H:i');
        $amount = number_format($payment->amount, 2, '.', '');
        $xml = $this->buildCreateTokenXml($payment, $description, $serviceDate, $amount);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/xml; charset=utf-8',
                'Accept' => 'application/xml',
            ])->withBody($xml, 'application/xml')
                ->post(rtrim($this->config['base_url'], '/') . '/API/v6/');

            $body = (string) $response->body();

            if (!$response->successful()) {
                throw new \Exception($body);
            }

            $parsed = $this->parseXml($body);
            $result = (string) ($parsed['Result'] ?? '');

            if ($result !== '000') {
                throw new \Exception((string) ($parsed['ResultExplanation'] ?? 'DPO token creation failed.'));
            }

            $transToken = (string) ($parsed['TransToken'] ?? '');

            if ($transToken === '') {
                throw new \Exception('DPO did not return a transaction token.');
            }

            return new PaymentResponseData(
                true,
                $this->gatewayName,
                $transToken,
                $this->checkoutUrl($transToken),
                'pending',
                false,
                'DPO checkout URL generated successfully.',
                $parsed
            );
        } catch (\Throwable $e) {
            throw new \Exception('DPO Payment Initialization Failed: ' . $e->getMessage());
        }
    }

    protected function verifyPayment(PaymentSession $session, Request $request): PaymentResponseData
    {
        // verifyToken against the TransToken stored at initiation.
        // Result 000 = transaction paid.
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><API3G></API3G>');
        $xml->addChild('CompanyToken', htmlspecialchars($this->config['company_token']));
        $xml->addChild('Request', 'verifyToken');
        $xml->addChild('TransactionToken', htmlspecialchars((string) $session->payment_id));

        $response = Http::withHeaders([
            'Content-Type' => 'application/xml; charset=utf-8',
            'Accept' => 'application/xml',
        ])->withBody($xml->asXML() ?: '', 'application/xml')
            ->post(rtrim($this->config['base_url'], '/') . '/API/v6/');

        $parsed = $this->parseXml((string) $response->body());
        $result = (string) ($parsed['Result'] ?? '');

        if ($result === '000') {
            $paymentId = (string) ($parsed['TransactionRef'] ?? $session->payment_id);

            return $this->verified($session, $paymentId, $parsed, 'DPO payment verified.');
        }

        $status = match ($result) {
            '900' => 'pending',
            '904' => 'cancelled',
            default => 'failed',
        };

        return $this->unverified(
            $session,
            'DPO reports: ' . ((string) ($parsed['ResultExplanation'] ?? "result code '{$result}'")),
            $parsed,
            $status
        );
    }

    protected function buildCreateTokenXml(
        PaymentRequestData $payment,
        string $description,
        string $serviceDate,
        string $amount
    ): string {
        $firstName = $this->extractFirstName($payment->customer->name ?? 'Customer');
        $lastName = $this->extractLastName($payment->customer->name ?? 'Customer');
        $email = $payment->customer->email ?? '[email protected]';

        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><API3G></API3G>');
        $xml->addChild('CompanyToken', htmlspecialchars($this->config['company_token']));
        $xml->addChild('Request', 'createToken');

        $transaction = $xml->addChild('Transaction');
        $transaction->addChild('PaymentAmount', $amount);
        $transaction->addChild('PaymentCurrency', $payment->currency);
        $transaction->addChild('CompanyRef', htmlspecialchars($payment->orderId));
        $transaction->addChild('RedirectURL', htmlspecialchars($this->successUrl()));
        $transaction->addChild('BackURL', htmlspecialchars($this->cancelUrl()));
        $transaction->addChild('CompanyRefUnique', '0');
        $transaction->addChild('PTL', (string) ($payment->meta['ptl'] ?? 5));
        $transaction->addChild('customerFirstName', htmlspecialchars($firstName));
        $transaction->addChild('customerLastName', htmlspecialchars($lastName));
        $transaction->addChild('customerEmail', htmlspecialchars($email));

        $services = $xml->addChild('Services');
        $service = $services->addChild('Service');
        $service->addChild('ServiceType', htmlspecialchars((string) $this->config['service_type']));
        $service->addChild('ServiceDescription', htmlspecialchars($description));
        $service->addChild('ServiceDate', htmlspecialchars($serviceDate));

        return $xml->asXML() ?: '';
    }

    protected function parseXml(string $xml): array
    {
        $element = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);

        if ($element === false) {
            throw new \Exception('Unable to parse DPO XML response.');
        }

        return json_decode(json_encode($element), true) ?? [];
    }

    protected function checkoutUrl(string $transToken): string
    {
        return rtrim($this->config['checkout_base_url'], '?&') . '?ID=' . urlencode($transToken);
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
}
