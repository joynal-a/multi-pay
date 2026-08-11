<?php

namespace Abedin\MultiPay\Http\Controllers;

use Abedin\MultiPay\Models\PaymentSession;
use Abedin\MultiPay\Support\AppRoutes;
use Illuminate\View\View;

/**
 * Generic auto-submitting form page for gateways that require a signed
 * browser POST (CCAvenue, Paytm, PayFort, PayHere, ...). The gateway service
 * prepares the fields at pay() time and stores them on the session under
 * raw_response.multipay_form.
 */
class FormPostCheckoutController
{
    public function show($sessionId): View
    {
        $session = PaymentSession::resolveByIdentifier($sessionId);
        $form = (array) data_get($session->raw_response, 'multipay_form');

        abort_if(empty($form['action']) || empty($form['fields']), 404, 'No checkout form prepared for this session.');

        return view('joynala.multi-pay::form-post.checkout', [
            'session' => $session,
            'gatewayLabel' => (string) ($form['label'] ?? ucfirst($session->gateway)),
            'actionUrl' => (string) $form['action'],
            'method' => strtoupper((string) ($form['method'] ?? 'POST')),
            'fields' => (array) $form['fields'],
            'cancelUrl' => AppRoutes::url('cancel', $session->routeIdentifier()),
        ]);
    }
}
