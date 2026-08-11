<?php

namespace Abedin\MultiPay\Http\Controllers;

use Abedin\MultiPay\Models\PaymentSession;
use Abedin\MultiPay\Services\HyperpayGateway;
use Abedin\MultiPay\Support\AppRoutes;
use Illuminate\View\View;

class HyperpayCheckoutController
{
    public function show($sessionId): View
    {
        $session = PaymentSession::resolveByIdentifier($sessionId);
        $gateway = new HyperpayGateway();
        $gateway->initialize('hyperpay');

        return view('joynala.multi-pay::hyperpay.checkout', [
            'session' => $session,
            'scriptUrl' => $gateway->widgetScriptUrl($session),
            'brands' => $gateway->paymentBrands(),
            'shopperResultUrl' => AppRoutes::url('success', $session->routeIdentifier()),
            'cancelUrl' => AppRoutes::url('cancel', $session->routeIdentifier()),
        ]);
    }
}
