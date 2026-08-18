<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Models\PaymentSession;
use Illuminate\Http\Request;

/**
 * Fygaro (Caribbean / Latin America) payment button with JWT-locked amounts.
 *
 * Fygaro's return and hook URLs are configured STATICALLY on the payment
 * button (dashboard → Advanced Options), so point them at the package's
 * bridge endpoints once:
 *
 *   Return URL: https://your-app.com/multipay/fygaro/return
 *   Hook URL:   https://your-app.com/multipay/fygaro/hook
 *
 * The bridge resolves the session from custom_reference (the session UUID)
 * and hands off to the app's own routes / confirm().
 */
class FygaroGateway extends BaseGateway
{
    public function needyConfig(): array
    {
        return [
            'button_url',
            'api_key',
            'secret_key',
        ];
    }

    protected function requestPayment(PaymentRequestData $payment): PaymentResponseData
    {
        $now = time();

        // JWT locks the amount server-side: tampering breaks the signature
        // and Fygaro rejects the checkout.
        $jwt = $this->buildJwt([
            'amount' => number_format($payment->amount, 2, '.', ''),
            'currency' => strtoupper($payment->currency ?: 'USD'),
            'custom_reference' => $this->sessionIdentifier(),
            'nbf' => $now - 60,
            'exp' => $now + 3600,
        ]);

        return new PaymentResponseData(
            true,
            $this->gatewayName,
            null, // Fygaro issues its reference only after payment
            rtrim($this->config['button_url'], '/') . '/?jwt=' . $jwt,
            'pending',
            false,
            'Fygaro payment link generated successfully.',
            ['custom_reference' => $this->sessionIdentifier()]
        );
    }

    protected function verifyPayment(PaymentSession $session, Request $request): PaymentResponseData
    {
        // Proof arrives on the hook: Fygaro-Signature is an HMAC-SHA256 over
        // "timestamp.rawBody" with our secret. The browser return redirect
        // carries nothing verifiable.
        $signatureHeader = (string) $request->header('Fygaro-Signature', '');

        if ($signatureHeader === '') {
            return $this->unverified(
                $session,
                'No Fygaro signature in this request — confirmation arrives on the hook URL.',
                [],
                'pending'
            );
        }

        $rawBody = (string) $request->getContent();
        [$timestamp, $hashes] = $this->parseSignatureHeader($signatureHeader);

        if ($timestamp === '' || $hashes === []) {
            return $this->unverified($session, 'Fygaro signature header is malformed.', []);
        }

        // Replay protection per Fygaro's verification spec.
        if (abs(time() - (int) $timestamp) > 300) {
            return $this->unverified($session, 'Fygaro hook timestamp is too old — possible replay.', []);
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $this->config['secret_key']);
        $signatureOk = false;

        foreach ($hashes as $hash) {
            if (hash_equals($expected, strtolower($hash))) {
                $signatureOk = true;
                break;
            }
        }

        $payload = (array) json_decode($rawBody, true);

        if (!$signatureOk) {
            return $this->unverified($session, 'Fygaro hook signature does not match — response rejected.', $payload);
        }

        $referenceOk = (string) ($payload['customReference'] ?? '') === $session->routeIdentifier();
        $amountOk = (float) ($payload['amount'] ?? 0) >= (float) $session->amount;
        $currencyOk = strtoupper((string) ($payload['currency'] ?? '')) === strtoupper((string) $session->currency);

        if ($referenceOk && $amountOk && $currencyOk) {
            $paymentId = (string) ($payload['transactionId'] ?? $payload['reference'] ?? $session->payment_id);

            return $this->verified($session, $paymentId, $payload, 'Fygaro payment verified.');
        }

        return $this->unverified(
            $session,
            'Fygaro hook reference/amount/currency does not match the session.',
            $payload
        );
    }

    protected function buildJwt(array $payload): string
    {
        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT',
            'kid' => $this->config['api_key'],
        ];

        $input = $this->base64Url((string) json_encode($header))
            . '.' . $this->base64Url((string) json_encode($payload));

        $signature = $this->base64Url(hash_hmac('sha256', $input, $this->config['secret_key'], true));

        return $input . '.' . $signature;
    }

    protected function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Header format: t=<timestamp>,v1=<hash>[,v1=<hash>...]
     *
     * @return array{0: string, 1: array<int, string>}
     */
    protected function parseSignatureHeader(string $header): array
    {
        $timestamp = '';
        $hashes = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1' && $value !== '') {
                $hashes[] = $value;
            }
        }

        return [$timestamp, $hashes];
    }
}
