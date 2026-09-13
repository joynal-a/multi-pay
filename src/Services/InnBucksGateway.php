<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Models\PaymentSession;
use Abedin\MultiPay\Support\AppRoutes;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * InnBucks (Zimbabwe) Merchant API — pay-by-code.
 *
 * The API issues a payment code (plus QR) that the customer approves in the
 * InnBucks app / USSD. The payment URL is the package's waiting page, which
 * shows the code and polls the code inquiry until it resolves.
 *
 * All amounts are sent in CENTS. USD only.
 *
 * base_url: https://staging.innbucks.co.zw (staging) / live URL issued by InnBucks
 */
class InnBucksGateway extends BaseGateway
{
    public function needyConfig(): array
    {
        return [
            'base_url',
            'api_key',
            'username',
            'password',
        ];
    }

    protected function requestPayment(PaymentRequestData $payment): PaymentResponseData
    {
        if (strtoupper($payment->currency ?: 'USD') !== 'USD') {
            throw new \Exception("InnBucks only accepts USD payments, got '{$payment->currency}'.");
        }

        $amountCents = $this->amountInMinorUnits($payment->amount);

        $response = $this->authed('/api/code/generate', [
            'reference' => $this->sessionIdentifier(),
            'narration' => (string) ($payment->meta['title'] ?? ('Order ' . $payment->orderId)),
            'amount' => $amountCents,
            'type' => 'PAYMENT',
        ]);

        $data = (array) $response->json();
        $code = (string) ($data['code'] ?? '');

        if (!$response->successful() || (int) ($data['responseCode'] ?? -1) !== 0 || $code === '') {
            throw new \Exception(
                'InnBucks Payment Initialization Failed: '
                . ($data['responseMsg'] ?? $data['responseDescription'] ?? ('HTTP ' . $response->status()))
            );
        }

        // Guard against a cents/dollars mix-up on the platform side.
        if (isset($data['amount']) && (int) $data['amount'] !== $amountCents) {
            throw new \Exception('InnBucks echoed a different amount than requested — payment code discarded.');
        }

        return new PaymentResponseData(
            true,
            $this->gatewayName,
            $code,
            AppRoutes::internalUrl('mobile-money.checkout', $this->sessionIdentifier()),
            'pending',
            false,
            'InnBucks payment code generated successfully.',
            [
                'innbucks_code' => $code,
                'auth_number' => $data['authNumber'] ?? null,
                'qr_code' => $data['qrCode'] ?? null,
                'expires_at' => $data['expiryDate'] ?? null,
                'amount_cents' => $amountCents,
            ]
        );
    }

    protected function verifyPayment(PaymentSession $session, Request $request): PaymentResponseData
    {
        // Always ask InnBucks directly — nothing in the request is trusted.
        $code = (string) (data_get($session->raw_response, 'innbucks_code') ?? $session->payment_id);

        if ($code === '') {
            return $this->unverified($session, 'InnBucks session has no payment code to look up.', []);
        }

        $response = $this->authed('/api/code/inquiry', [
            'reference' => 'Q-' . Str::uuid(),
            'code' => $code,
        ]);

        // InnBucks can return a final status inside a non-2xx / non-zero
        // envelope, so the status field is authoritative when present.
        $data = (array) $response->json();
        $status = strtoupper((string) preg_replace('/[\s_-]+/', '', (string) ($data['status'] ?? '')));
        $expectedCents = (int) (data_get($session->raw_response, 'amount_cents')
            ?? $this->amountInMinorUnits((float) $session->amount));
        $amountOk = !isset($data['amount']) || (int) $data['amount'] >= $expectedCents;

        // Per the docs both Paid and Claimed mean the customer finalised it.
        if (in_array($status, ['PAID', 'CLAIMED'], true)) {
            if ($amountOk) {
                return $this->verified($session, $code, $data, 'InnBucks payment verified.');
            }

            return $this->unverified($session, 'InnBucks amount does not match the session.', $data);
        }

        if (in_array($status, ['EXPIRED', 'TIMEDOUT'], true)) {
            return $this->unverified($session, 'InnBucks payment code expired unpaid.', $data, 'failed');
        }

        return $this->unverified(
            $session,
            $status === '' || $status === 'NEW' || $status === 'PENDING'
                ? 'InnBucks payment code is still waiting for the customer.'
                : "InnBucks reports code status '{$status}'.",
            $data,
            'pending'
        );
    }

    /**
     * POST with X-Api-Key + Bearer token; on 401 refresh the token and replay once.
     */
    protected function authed(string $path, array $body): Response
    {
        $send = fn (string $token) => Http::withHeaders([
            'X-Api-Key' => $this->config['api_key'],
            'Authorization' => 'Bearer ' . $token,
        ])->acceptJson()->post($this->url($path), $body);

        $response = $send($this->token());

        if ($response->status() === 401) {
            $response = $send($this->token(true));
        }

        return $response;
    }

    protected function token(bool $refresh = false): string
    {
        $cacheKey = 'multipay.innbucks.token.' . sha1($this->config['base_url'] . '|' . $this->config['username']);

        if (!$refresh && ($cached = Cache::get($cacheKey))) {
            return $cached;
        }

        $response = Http::withHeaders(['X-Api-Key' => $this->config['api_key']])
            ->acceptJson()
            ->post($this->url('/auth/third-party'), [
                'username' => $this->config['username'],
                'password' => $this->config['password'],
            ]);

        $token = (string) $response->json('accessToken', '');

        if (!$response->successful() || $token === '') {
            throw new \Exception('InnBucks login failed — check api_key/username/password (HTTP ' . $response->status() . ').');
        }

        Cache::put($cacheKey, $token, $this->tokenTtl($token));

        return $token;
    }

    /**
     * Seconds until the JWT expires (minus 30s), falling back to 5 minutes.
     */
    protected function tokenTtl(string $jwt): int
    {
        $parts = explode('.', $jwt);
        $claims = isset($parts[1])
            ? (array) json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true)
            : [];

        $ttl = isset($claims['exp']) ? (int) $claims['exp'] - time() - 30 : 300;

        return max(1, $ttl);
    }

    protected function url(string $path): string
    {
        return rtrim($this->config['base_url'], '/') . $path;
    }
}
