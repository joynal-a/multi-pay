<?php

namespace Abedin\MultiPay\Http\Controllers;

use Abedin\MultiPay\Models\PaymentSession;
use Abedin\MultiPay\Services\CashfreeGateway;
use Abedin\MultiPay\Support\AppRoutes;
use Illuminate\View\View;

class CashfreeCheckoutController
{
    public function show($sessionId): View
    {
        $session = PaymentSession::resolveByIdentifier($sessionId);
        $gateway = new CashfreeGateway();
        $gateway->initialize('cashfree');

        return view('joynala.multi-pay::cashfree.checkout', [
            'session' => $session,
            'paymentSessionId' => (string) $session->payment_id,
            'mode' => $gateway->checkoutMode(),
            'cancelUrl' => AppRoutes::url('cancel', $session->routeIdentifier()),
        ]);
    }
}
