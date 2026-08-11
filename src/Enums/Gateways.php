<?php

namespace Abedin\MultiPay\Enums;

/**
 * Values match the registry keys in BaseManager / config('multipay.gateways').
 */
enum Gateways: string
{
    case Demo = 'demo';
    case Stripe = 'stripe';
    case Razorpay = 'razorpay';
    case Paystack = 'paystack';
    case Paytabs = 'paytabs';
    case Adyen = 'adyen';
    case Square = 'square';
    case Braintree = 'braintree';
    case Flutterwave = 'flutterwave';
    case Hesabe = 'hesabe';
    case Tingg = 'tingg';
    case Mollie = 'mollie';
    case MercadoPago = 'mercadopago';
    case Onafriq = 'onafriq';
    case PalmPay = 'palmpay';
    case Dpo = 'dpo';
    case Payu = 'payu';
    case Cashfree = 'cashfree';
    case Worldpay = 'worldpay';
    case AuthorizeNet = 'authorizenet';
    case CheckoutCom = 'checkout';
    case Telr = 'telr';
    case Moyasar = 'moyasar';
    case Iyzico = 'iyzico';
    case Ccavenue = 'ccavenue';
    case Paytm = 'paytm';
    case Payhere = 'payhere';
    case Fawry = 'fawry';
    case Payfort = 'payfort';
    case Hyperpay = 'hyperpay';
    case Ngenius = 'ngenius';
    case TwoCheckout = 'twocheckout';
    case Voguepay = 'voguepay';
    case Toppay = 'toppay';
    case Stepay = 'stepay';
}
