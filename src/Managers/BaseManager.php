<?php

namespace Abedin\MultiPay\Managers;

class BaseManager
{
    /**
     * Central registry for all supported gateway adapters.
     *
     * Add new gateways here and the manager API stays unchanged.
     *
     * @var array<string, class-string>
     */
    protected array $gateways = [
        'demo' => \Abedin\MultiPay\Services\DemoGateway::class,
        'stripe' => \Abedin\MultiPay\Services\StripeGateway::class,
        'razorpay' => \Abedin\MultiPay\Services\RazorpayGateway::class,
        'paystack' => \Abedin\MultiPay\Services\PaystackGateway::class,
        'paytabs' => \Abedin\MultiPay\Services\PaytabsGateway::class,
        'adyen' => \Abedin\MultiPay\Services\AdyenGateway::class,
        'square' => \Abedin\MultiPay\Services\SquareGateway::class,
        'braintree' => \Abedin\MultiPay\Services\BraintreeGateway::class,
        'flutterwave' => \Abedin\MultiPay\Services\FlutterwaveGateway::class,
        'hesabe' => \Abedin\MultiPay\Services\HesabeGateway::class,
        'tingg' => \Abedin\MultiPay\Services\TinggGateway::class,
        'mollie' => \Abedin\MultiPay\Services\MollieGateway::class,
        'mercadopago' => \Abedin\MultiPay\Services\MercadoPagoGateway::class,
        'onafriq' => \Abedin\MultiPay\Services\OnafriqGateway::class,
        'palmpay' => \Abedin\MultiPay\Services\PalmPayGateway::class,
        'dpo' => \Abedin\MultiPay\Services\DpoGateway::class,
        'payu' => \Abedin\MultiPay\Services\PayuGateway::class,
        'cashfree' => \Abedin\MultiPay\Services\CashfreeGateway::class,
        'worldpay' => \Abedin\MultiPay\Services\WorldpayGateway::class,
        'authorizenet' => \Abedin\MultiPay\Services\AuthorizeNetGateway::class,
        'checkout' => \Abedin\MultiPay\Services\CheckoutComGateway::class,
        'telr' => \Abedin\MultiPay\Services\TelrGateway::class,
        'moyasar' => \Abedin\MultiPay\Services\MoyasarGateway::class,
        'iyzico' => \Abedin\MultiPay\Services\IyzicoGateway::class,
        'ccavenue' => \Abedin\MultiPay\Services\CcavenueGateway::class,
        'paytm' => \Abedin\MultiPay\Services\PaytmGateway::class,
        'payhere' => \Abedin\MultiPay\Services\PayhereGateway::class,
        'fawry' => \Abedin\MultiPay\Services\FawryGateway::class,
        'payfort' => \Abedin\MultiPay\Services\PayfortGateway::class,
        'hyperpay' => \Abedin\MultiPay\Services\HyperpayGateway::class,
        'ngenius' => \Abedin\MultiPay\Services\NgeniusGateway::class,
        'twocheckout' => \Abedin\MultiPay\Services\TwoCheckoutGateway::class,
        'voguepay' => \Abedin\MultiPay\Services\VoguepayGateway::class,
        'payfast' => \Abedin\MultiPay\Services\PayFastGateway::class,
        'fygaro' => \Abedin\MultiPay\Services\FygaroGateway::class,
        'toppay' => \Abedin\MultiPay\Services\ToppayGateway::class,
        'stepay' => \Abedin\MultiPay\Services\StepayGateway::class,
    ];

    protected function getGatewayClass(string $name): ?string
    {
        return $this->gateways[$name] ?? null;
    }
}
