<?php

namespace Abedin\MultiPay\Http\Controllers;

use Abedin\MultiPay\Facades\MultiPay;
use Abedin\MultiPay\Models\PaymentSession;
use Abedin\MultiPay\Support\AppRoutes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Waiting page for push / pay-by-code mobile money (EcoCash, InnBucks):
 * there is no gateway-hosted page to redirect to, so the customer waits
 * here while the page polls the gateway's status lookup.
 *
 * The status endpoint is read-only — it never persists or fires events.
 * Once a final status is seen the browser is sent to the app's own
 * success/failure route, where MultiPay::confirm() re-verifies server-side.
 */
class MobileMoneyCheckoutController
{
    protected const GATEWAYS = [
        'ecocash' => 'EcoCash',
        'innbucks' => 'InnBucks',
    ];

    public function show($sessionId): View
    {
        $session = $this->resolve($sessionId);
        $raw = (array) $session->raw_response;

        return view('joynala.multi-pay::mobile-money.checkout', [
            'session' => $session,
            'gateway' => $session->gateway,
            'gatewayLabel' => self::GATEWAYS[$session->gateway],
            'msisdn' => $raw['customer_msisdn'] ?? null,
            'code' => $raw['innbucks_code'] ?? null,
            'qrCode' => $raw['qr_code'] ?? null,
            'statusUrl' => AppRoutes::internalUrl('mobile-money.status', $session->routeIdentifier()),
            'successUrl' => AppRoutes::url('success', $session->routeIdentifier()),
            'failureUrl' => AppRoutes::url('failure', $session->routeIdentifier()),
            'cancelUrl' => AppRoutes::url('cancel', $session->routeIdentifier()),
        ]);
    }

    public function status(Request $request, $sessionId): JsonResponse
    {
        $session = $this->resolve($sessionId);

        if (in_array($session->status, ['paid', 'failed', 'cancelled'], true)) {
            return response()->json(['status' => $session->status, 'message' => null]);
        }

        try {
            $result = MultiPay::gateway($session->gateway)->verify($session, $request);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'pending', 'message' => 'Still checking payment status…']);
        }

        return response()->json(['status' => $result->status, 'message' => $result->message]);
    }

    protected function resolve($sessionId): PaymentSession
    {
        $session = PaymentSession::resolveByIdentifier($sessionId);

        abort_unless(isset(self::GATEWAYS[$session->gateway]), 404);

        return $session;
    }
}
