<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Models\PaymentSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class HesabeGateway extends BaseGateway
{
    public function needyConfig(): array
    {
        return [
            'merchant_code',
            'access_code',
            'encryption_key',
            'iv_key',
        ];
    }

    protected function requestPayment(PaymentRequestData $payment): PaymentResponseData
    {
        $callbackUrl = $this->callbackUrl();
        $payload = [
            'merchantCode' => $this->config['merchant_code'],
            'amount' => $this->formatAmount($payment->amount),
            'paymentType' => '0',
            'currency' => $payment->currency,
            'responseUrl' => $this->successUrl(),
            'failureUrl' => $this->failureUrl(),
            'version' => '2.0',
            'orderReferenceNumber' => $payment->orderId,
            'name' => $payment->customer->name,
            'mobile_number' => $payment->meta['mobile_number'] ?? null,
            'email' => $payment->customer->email,
            'variable1' => $payment->meta['variable1'] ?? $this->sessionIdentifier(),
            'variable2' => $payment->meta['variable2'] ?? null,
            'variable3' => $payment->meta['variable3'] ?? null,
            'variable4' => $payment->meta['variable4'] ?? null,
            'variable5' => $payment->meta['variable5'] ?? null,
        ];

        if ($this->shouldSendWebhookUrl($callbackUrl)) {
            $payload['webhookUrl'] = $callbackUrl;
        }

        $payload = array_filter($payload, static fn ($value) => $value !== null && $value !== '');

        try {
            $encrypted = $this->encryptPayload(json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}');

            $response = Http::withHeaders([
                'accessCode' => $this->config['access_code'],
                'Accept' => 'application/json',
            ])->asForm()->post(rtrim($this->baseUrl(), '/') . '/checkout', [
                'data' => $encrypted,
            ]);

            $body = trim((string) $response->body());
            $decoded = $this->decodeHesabeResponse($body);

            if (!$response->successful()) {
                throw new \Exception(
                    is_array($decoded)
                        ? (string) ($decoded['message'] ?? $decoded['error']['message'] ?? json_encode($decoded))
                        : $body
                );
            }

            if (!is_array($decoded) || !($decoded['status'] ?? false)) {
                throw new \Exception(is_array($decoded) ? (string) ($decoded['message'] ?? 'Hesabe checkout failed.') : 'Unable to decode Hesabe response.');
            }

            $token = data_get($decoded, 'response.data');

            if (!$token || !is_string($token)) {
                throw new \Exception('Hesabe did not return a payment token.');
            }

            return new PaymentResponseData(
                true,
                $this->gatewayName,
                $payment->orderId,
                rtrim($this->baseUrl(), '/') . '/payment?data=' . $token,
                'pending',
                false,
                'Hesabe checkout URL generated successfully.',
                $decoded
            );
        } catch (\Throwable $e) {
            throw new \Exception('Hesabe Payment Initialization Failed: ' . $e->getMessage());
        }
    }

    protected function verifyPayment(PaymentSession $session, Request $request): PaymentResponseData
    {
        // Hesabe returns an AES-encrypted `data` payload to the response URL.
        // Only something encrypted with our merchant key can decrypt cleanly,
        // so a valid decryption + matching order reference is the proof.
        $encrypted = (string) $request->input('data', '');

        if ($encrypted === '') {
            return $this->unverified($session, 'Hesabe response payload (data) is missing.', [], 'pending');
        }

        try {
            $decoded = json_decode($this->decryptPayload($encrypted), true);
        } catch (\Throwable $e) {
            return $this->unverified($session, 'Hesabe response payload could not be decrypted: ' . $e->getMessage());
        }

        if (!is_array($decoded)) {
            return $this->unverified($session, 'Hesabe response payload is not valid JSON.');
        }

        $resultCode = strtoupper((string) data_get($decoded, 'response.resultCode', ''));
        $statusOk = (bool) ($decoded['status'] ?? false);
        $orderRef = (string) data_get($decoded, 'response.orderReferenceNumber', '');
        $paidAmount = (float) data_get($decoded, 'response.amount', 0);

        $orderOk = $orderRef === '' || $orderRef === (string) $session->order_id;
        $amountOk = $paidAmount <= 0 || $paidAmount >= (float) $session->amount;

        if ($statusOk && $resultCode === 'CAPTURED' && $orderOk && $amountOk) {
            $paymentId = (string) data_get($decoded, 'response.paymentToken', $session->payment_id);

            return $this->verified($session, $paymentId, $decoded, 'Hesabe payment verified.');
        }

        return $this->unverified(
            $session,
            "Hesabe reports resultCode '" . ($resultCode ?: 'unknown') . "'.",
            $decoded
        );
    }

    protected function decodeHesabeResponse(string $body): ?array
    {
        try {
            $decrypted = $this->decryptPayload($body);
            $decoded = json_decode($decrypted, true);

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            $decoded = json_decode($body, true);

            return is_array($decoded) ? $decoded : null;
        }
    }

    protected function formatAmount(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 3, '.', ''), '0'), '.');
    }

    protected function encryptPayload(string $plainText): string
    {
        $padded = $this->pkcs5Pad($plainText);
        $encrypted = openssl_encrypt(
            $padded,
            'AES-256-CBC',
            $this->config['encryption_key'],
            OPENSSL_ZERO_PADDING,
            $this->config['iv_key']
        );

        if ($encrypted === false) {
            throw new \Exception('Unable to encrypt Hesabe payload.');
        }

        $binary = base64_decode($encrypted, true);

        if ($binary === false) {
            throw new \Exception('Unable to decode Hesabe encrypted payload.');
        }

        return urlencode(bin2hex($binary));
    }

    protected function decryptPayload(string $cipherHex): string
    {
        $decodedHex = urldecode($cipherHex);

        if (!(ctype_xdigit($decodedHex) && strlen($decodedHex) % 2 === 0)) {
            throw new \Exception('Invalid Hesabe encrypted response format.');
        }

        $binary = hex2bin($decodedHex);

        if ($binary === false) {
            throw new \Exception('Unable to decode Hesabe response hex.');
        }

        $decrypted = openssl_decrypt(
            base64_encode($binary),
            'AES-256-CBC',
            $this->config['encryption_key'],
            OPENSSL_ZERO_PADDING,
            $this->config['iv_key']
        );

        if ($decrypted === false) {
            throw new \Exception('Unable to decrypt Hesabe response.');
        }

        $unpadded = $this->pkcs5Unpad($decrypted);

        if ($unpadded === false) {
            throw new \Exception('Unable to unpad Hesabe response.');
        }

        return $unpadded;
    }

    protected function pkcs5Pad(string $text): string
    {
        $blockSize = 32;
        $pad = $blockSize - (strlen($text) % $blockSize);

        return $text . str_repeat(chr($pad), $pad);
    }

    protected function pkcs5Unpad(string $text): string|false
    {
        $pad = ord($text[strlen($text) - 1]);

        if ($pad > strlen($text)) {
            return false;
        }

        if (strspn($text, chr($pad), strlen($text) - $pad) !== $pad) {
            return false;
        }

        return substr($text, 0, -1 * $pad);
    }

    protected function shouldSendWebhookUrl(string $callbackUrl): bool
    {
        $flag = $this->rawConfig['send_webhook_url'] ?? true;

        return $this->normalizeFlag($flag) && $this->isPublicUrl($callbackUrl);
    }

    protected function baseUrl(): string
    {
        if (!empty($this->rawConfig['base_url'])) {
            return (string) $this->rawConfig['base_url'];
        }

        $mode = strtolower((string) ($this->rawConfig['mode'] ?? 'sandbox'));

        return $mode === 'production'
            ? 'https://api.hesabe.com'
            : 'https://sandbox.hesabe.com';
    }

    protected function normalizeFlag(mixed $flag): bool
    {
        if (is_bool($flag)) {
            return $flag;
        }

        if (is_string($flag)) {
            return filter_var($flag, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        }

        return (bool) $flag;
    }

    protected function isPublicUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (!$host || $host === 'localhost') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if ($host === '127.0.0.1' || $host === '::1') {
                return false;
            }

            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) !== false;
        }

        return true;
    }
}
