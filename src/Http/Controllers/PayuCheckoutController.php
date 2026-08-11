<?php

namespace Abedin\MultiPay\Http\Controllers;

use Abedin\MultiPay\Models\PaymentSession;
use Abedin\MultiPay\Services\PayuGateway;
use Abedin\MultiPay\Support\AppRoutes;
use Illuminate\View\View;

class PayuCheckoutController
{
    public function show($sessionId): View
    {
        $session = PaymentSession::resolveByIdentifier($sessionId);
        $gateway = new PayuGateway();
        $gateway->initialize('payu');

        return view('joynala.multi-pay::payu.checkout', [
            'session' => $session,
            'actionUrl' => $gateway->paymentEndpoint(),
            'fields' => $gateway->buildHostedCheckoutFields($session),
            'cancelUrl' => AppRoutes::url('cancel', $session->routeIdentifier()),
        ]);
    }
}
