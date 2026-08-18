<?php

namespace Abedin\MultiPay\Http\Controllers;

use Abedin\MultiPay\Facades\MultiPay;
use Abedin\MultiPay\Models\PaymentSession;
use Abedin\MultiPay\Support\AppRoutes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Fygaro configures return/hook URLs statically per payment button, so these
 * two fixed endpoints bridge back into the per-session flow using
 * custom_reference (the session UUID).
 */
class FygaroBridgeController
{
    /**
     * Browser return: forward the customer to the app's own success route.
     */
    public function return(Request $request): RedirectResponse
    {
        $identifier = (string) ($request->query('customReference') ?: $request->query('custom_reference', ''));
        $session = PaymentSession::resolveByIdentifier($identifier);

        return redirect()->to(
            AppRoutes::url('success', $session->routeIdentifier())
            . '?reference=' . urlencode((string) $request->query('reference', ''))
        );
    }

    /**
     * Server-to-server hook: verify (signature checked inside confirm() via
     * the gateway) and acknowledge with 200.
     */
    public function hook(Request $request): JsonResponse
    {
        $payload = (array) json_decode((string) $request->getContent(), true);
        $identifier = (string) ($payload['customReference'] ?? '');

        $result = MultiPay::confirm($identifier, $request);

        return response()->json([
            'ok' => $result->success,
            'status' => $result->status,
        ]);
    }
}
