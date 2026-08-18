<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Models\PaymentSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * PayFast (South Africa) hosted checkout.
 * base_url: https://sandbox.payfast.co.za (sandbox) / https://www.payfast.co.za (live)
 */
class PayFastGateway extends BaseGateway
{
    public function needyConfig(): array
    {
        return [
            'merchant_id',
            'merchant_key',
            'base_url',
        ];
    }

    protected function requestPayment(PaymentRequestData $payment): PaymentResponseData
    {
        $name = trim((string) ($payment->customer->name ?? 'Customer'));
        $parts = preg_split('/\s+/', $name) ?: ['Customer'];

        // Field order matters: the signature hashes the fields exactly as
        // they appear in PayFast's form specification.
        $fields = array_filter([
            'merchant_id' => $this->config['merchant_id'],
            'merchant_key' => $this->config['merchant_key'],
            'return_url' => $this->successUrl(),
            'cancel_url' => $this->cancelUrl(),
            'notify_url' => $this->callbackUrl(),
            'name_first' => $parts[0] ?? 'Customer',
            'name_last' => count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '',
            'email_address' => (string) ($payment->customer->email ?? ''),
            'm_payment_id' => $payment->orderId,
            'amount' => number_format($payment->amount, 2, '.', ''),
            'item_name' => (string) ($payment->meta['title'] ?? ('Order ' . $payment->orderId)),
            'item_description' => (string) ($payment->meta['description'] ?? ''),
        ], static fn ($value) => $value !== '' && $value !== null);

        $fields['signature'] = $this->signature($fields);

        return $this->formPostResponse(
            $payment,
            rtrim($this->config['base_url'], '/') . '/eng/process',
            $fields,
            'PayFast'
        );
    }

    protected function verifyPayment(PaymentSession $session, Request $request): PaymentResponseData
    {
        // Proof arrives on the ITN (notify) POST: a signature over the posted
        // fields, confirmed server-side against PayFast's validate endpoint.
        // The browser return_url carries nothing verifiable.
        $posted = $request->post();

        if (!isset($posted['signature'], $posted['payment_status'])) {
            return $this->unverified(
                $session,
                'No PayFast ITN payload in this request — confirmation arrives on the notify (callback) URL.',
                [],
                'pending'
            );
        }

        // 1. Signature over the fields in the exact order received.
        $expected = $this->signature(array_diff_key($posted, ['signature' => true]));

        if (!hash_equals($expected, strtolower((string) $posted['signature']))) {
            return $this->unverified($session, 'PayFast ITN signature does not match — response rejected.', $posted);
        }

        // 2. Server-side confirmation with PayFast itself.
        $validate = Http::asForm()->post(
            rtrim($this->config['base_url'], '/') . '/eng/query/validate',
            $posted
        );

        if (trim((string) $validate->body()) !== 'VALID') {
            return $this->unverified($session, 'PayFast could not validate this ITN payload.', $posted);
        }

        // 3. Bind to this session: our reference, the amount, and the status.
        $orderOk = (string) ($posted['m_payment_id'] ?? '') === (string) $session->order_id;
        $amountOk = (float) ($posted['amount_gross'] ?? 0) >= (float) $session->amount;
        $status = strtoupper((string) $posted['payment_status']);

        if ($status === 'COMPLETE' && $orderOk && $amountOk) {
            $paymentId = (string) ($posted['pf_payment_id'] ?? $session->payment_id);

            return $this->verified($session, $paymentId, $posted, 'PayFast payment verified.');
        }

        $mapped = match ($status) {
            'PENDING' => 'pending',
            'CANCELLED' => 'cancelled',
            default => 'failed',
        };

        return $this->unverified(
            $session,
            $status === 'COMPLETE'
                ? 'PayFast ITN order/amount does not match the session.'
                : "PayFast reports payment status '{$status}'.",
            $posted,
            $mapped
        );
    }

    /**
     * PayFast signature: urlencoded name=value pairs in given order, with the
     * account passphrase appended when configured, md5-hashed.
     */
    protected function signature(array $fields): string
    {
        $segments = [];

        foreach ($fields as $key => $value) {
            if ($value !== '' && $value !== null) {
                $segments[] = $key . '=' . urlencode(trim((string) $value));
            }
        }

        $string = implode('&', $segments);
        $passphrase = trim((string) ($this->rawConfig['passphrase'] ?? ''));

        if ($passphrase !== '') {
            $string .= '&passphrase=' . urlencode($passphrase);
        }

        return md5($string);
    }
}
