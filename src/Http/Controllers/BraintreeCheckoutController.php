<?php

namespace Abedin\MultiPay\Http\Controllers;

use Abedin\MultiPay\Models\PaymentSession;
use Abedin\MultiPay\Services\BraintreeGateway;
use Abedin\MultiPay\Support\AppRoutes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BraintreeCheckoutController
{
    public function show($sessionId): View
    {
        $session = PaymentSession::resolveByIdentifier($sessionId);
        $gateway = new BraintreeGateway();
        $gateway->initialize('braintree');
        $sessionKey = $session->routeIdentifier();

        return view('joynala.multi-pay::braintree.checkout', [
            'session' => $session,
            'clientToken' => $gateway->generateClientToken(),
            'postUrl' => AppRoutes::internalUrl('braintree.process', $sessionKey),
            'cancelUrl' => AppRoutes::url('cancel', $sessionKey),
        ]);
    }

    public function process(Request $request, PaymentSession $session): RedirectResponse
    {
        $gateway = new BraintreeGateway();
        $gateway->initialize('braintree');

        $result = $gateway->captureHostedPayment(
            $session,
            (string) $request->input('payment_method_nonce')
        );

        $sessionKey = $session->routeIdentifier();

        if (!$result['success']) {
            return redirect()
                ->to(AppRoutes::internalUrl('braintree.checkout', $sessionKey))
                ->withInput()
                ->with('multipay_error', $result['message']);
        }

        return redirect()->to(AppRoutes::url('success', $sessionKey));
    }
}
