<?php

namespace Abedin\MultiPay\Managers;

use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Events\PaymentFailed;
use Abedin\MultiPay\Events\PaymentSucceeded;
use Abedin\MultiPay\Models\PaymentSession;
use Abedin\MultiPay\Services\BaseGateway;
use Illuminate\Http\Request;
use InvalidArgumentException;

class PaymentManager extends BaseManager
{
    /**
     * Process and initialize the gateway
     *
     * @param string $name
     * @param array $config Optional credential override; skips the database lookup.
     * @return BaseGateway
     */
    public function gateway($name, array $config = [])
    {
        $name = strtolower($name);

        $class = $this->getGatewayClass($name);
        if (!$class) {
            throw new InvalidArgumentException("Gateway '{$name}' is not registered.");
        }

        if (!class_exists($class)) {
            throw new \Exception("Gateway class '$class' not found");
        }

        $gateway = new $class();
        $gateway->initialize($name, $config);

        return $gateway;
    }

    /**
     * Verify a returning payment. Call this first thing in the success and
     * callback routes the host app owns:
     *
     *   $result = MultiPay::confirm($session, $request);
     *   if ($result->success) { ... }
     *
     * Looks up the session, dispatches to the gateway's server-side
     * verification, persists the outcome, and fires
     * PaymentSucceeded / PaymentFailed.
     */
    public function confirm(mixed $session, Request $request, array $config = []): PaymentResponseData
    {
        $session = PaymentSession::resolveByIdentifier($session);

        // Idempotent: a session already verified as paid stays paid.
        if ($session->status === 'paid') {
            return new PaymentResponseData(
                true,
                $session->gateway,
                $session->payment_id,
                $session->payment_url,
                'paid',
                false,
                'Payment already verified.',
                (array) $session->raw_response
            );
        }

        $gateway = $this->gateway($session->gateway, $config);

        try {
            $result = $gateway->verify($session, $request);
        } catch (\Throwable $e) {
            $result = new PaymentResponseData(
                false,
                $session->gateway,
                $session->payment_id,
                $session->payment_url,
                'failed',
                false,
                'Payment verification failed: ' . $e->getMessage(),
                []
            );
        }

        $session->update([
            'payment_id' => $result->paymentId ?? $session->payment_id,
            'status' => $result->status,
            'raw_response' => $result->raw ?: $session->raw_response,
        ]);

        if ($result->success) {
            event(new PaymentSucceeded($session, $result));
        } else {
            event(new PaymentFailed($session, $result));
        }

        return $result;
    }

    /**
     * The packaged SVG icon for a gateway (brand-colored tile), or null.
     * Handy for rendering gateway pickers on checkout pages.
     */
    public function iconSvg(string $name): ?string
    {
        return \Abedin\MultiPay\Support\GatewayConfigStore::iconSvg(strtolower($name));
    }

    /**
     * Only the ACTIVE gateways — name, label, and logo — ready to feed a
     * checkout page, a Blade view, or a JSON API response:
     *
     *   Route::get('/api/payment-methods', fn () => MultiPay::activeGateways());
     *
     * Each entry: name, label, icon (URL set by the admin UI or config, may
     * be null), icon_svg (packaged SVG markup), icon_data_uri (same SVG as a
     * ready-to-use <img src> value).
     */
    public function activeGateways(): array
    {
        return \Abedin\MultiPay\Support\GatewayConfigStore::activeGateways();
    }

    /**
     * Fetch a payment session by its public identifier (UUID) or instance.
     */
    public function session(mixed $identifier): PaymentSession
    {
        return PaymentSession::resolveByIdentifier($identifier);
    }

    /**
     * Mark a session cancelled (no money moved, so no gateway round-trip).
     */
    public function cancel(mixed $identifier): PaymentSession
    {
        $session = PaymentSession::resolveByIdentifier($identifier);

        if ($session->status === 'pending') {
            $session->update(['status' => 'cancelled']);
        }

        return $session;
    }
}
