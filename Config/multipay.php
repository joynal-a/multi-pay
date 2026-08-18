<?php

return [
    // The database table where gateway configurations are stored
    'data_table' => 'gateways',

    // The column in the database table that contains the JSON configuration
    'json_column' => 'data',

    /*
    |--------------------------------------------------------------------------
    | Host Application Routes
    |--------------------------------------------------------------------------
    |
    | The HOST APP owns the success / cancel / failure / callback endpoints —
    | that is where orders get updated and customers get redirected, and only
    | the app knows how to do that. The package only needs the route NAMES so
    | it can build the URLs it sends to the gateways.
    |
    | Register at least these routes in your app (GET+POST, CSRF-exempt for
    | the POST callbacks) and call MultiPay::confirm($session, $request) first
    | thing in the controller:
    |
    |   Route::match(['get', 'post'], '/payment/{session}/success', ...)->name('multipay.success');
    |   Route::match(['get', 'post'], '/payment/{session}/cancel',  ...)->name('multipay.cancel');
    |   Route::match(['get', 'post'], '/payment/{session}/failure', ...)->name('multipay.failure');
    |   Route::match(['get', 'post'], '/payment/{session}/callback', ...)->name('multipay.callback');
    |
    | pending / error / expiry are optional; when not registered they fall
    | back to success (pending) or failure (error, expiry).
    |
    */
    'routes' => [
        'success' => 'multipay.success',
        'cancel' => 'multipay.cancel',
        'failure' => 'multipay.failure',
        'callback' => 'multipay.callback',
        'pending' => 'multipay.pending',
        'error' => 'multipay.error',
        'expiry' => 'multipay.expiry',
    ],

    /*
    |--------------------------------------------------------------------------
    | Internal Routes
    |--------------------------------------------------------------------------
    |
    | Hosted checkout pages (Braintree drop-in, Cashfree JS, PayU form post)
    | are gateway plumbing, not business logic, so they stay in the package.
    |
    */
    'internal_routes' => [
        'prefix' => 'multipay',
        'as' => 'multipay.internal.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin UI Middleware
    |--------------------------------------------------------------------------
    |
    | Middleware for the gateway-manager endpoints used by the includable
    | blade (@include('joynala.multi-pay::admin.gateways')). These endpoints
    | edit live payment credentials — put YOUR admin middleware here.
    |
    */
    'admin_middleware' => ['web', 'auth'],

    'gateways' => [
        /**
         * Keys below map the package's required config names to the keys of
         * your JSON column (e.g. 'secret_key' => 'secret_key_data' means the
         * JSON stores the secret under "secret_key_data").
         * is_active: Whether the gateway is active or not
         * icon: URL to the gateway icon
         * Note: Don't change the keys, they are used internally.
         */
        'demo' => [
            'is_active' => true,
            'icon' => null, // packaged SVG tile is used unless you set a URL here
        ],

        'stripe' => [
            'is_active' => true,
            'secret_key' => 'secret_key_data',
            'public_key' => 'public_key_data',
            'icon' => null, // packaged SVG tile is used unless you set a URL here
        ],

        'razorpay' => [
            'is_active' => true,
            'secret_key' => 'secret_key_data',
            'public_key' => 'public_key_data',
            'icon' => null, // packaged SVG tile is used unless you set a URL here
        ],

        'paystack' => [
            'is_active' => true,
            'secret_key' => 'secret_key_data',
            'icon' => null, // packaged SVG tile is used unless you set a URL here
        ],

        'paytabs' => [
            'is_active' => true,
            'profile_id' => 'profile_id',
            'secret_key' => 'secret_key_data',
            'base_url' => 'base_url',
            'icon' => null, // packaged SVG tile is used unless you set a URL here
        ],

        'adyen' => [
            'is_active' => true,
            'api_key' => 'api_key_data',
            'merchant_account' => 'merchant_account_name',
            'country_code' => 'country_code_data',
            'icon' => null, // packaged SVG tile is used unless you set a URL here
        ],

        'square' => [
            'is_active' => true,
            'square_access_token' => 'access_token',
            'square_location' => 'location_id',
            'square_environment' => 'environment',
            'icon' => null, // packaged SVG tile is used unless you set a URL here
        ],

        'braintree' => [
            'is_active' => true,
            'environment' => 'environment',
            'merchant_id' => 'merchant_id',
            'public_key' => 'public_key',
            'private_key' => 'private_key',
            'icon' => null, // packaged SVG tile is used unless you set a URL here
        ],

        'flutterwave' => [
            'is_active' => true,
            'secret_key' => 'secret_key',
            'base_url' => 'base_url',
            'icon' => null, // packaged SVG tile is used unless you set a URL here
        ],

        'hesabe' => [
            'is_active' => true,
            'merchant_code' => 'merchant_code',
            'access_code' => 'access_code',
            'encryption_key' => 'secret_key',
            'iv_key' => 'iv_key',
            'mode' => 'mode',
            'send_webhook_url' => 'send_webhook_url',
            'icon' => null, // packaged SVG tile is used unless you set a URL here
        ],

        'tingg' => [
            'is_active' => true,
            'api_key' => 'api_key',
            'client_id' => 'client_id',
            'client_secret' => 'client_secret',
            'auth_base_url' => 'auth_base_url',
            'base_url' => 'base_url',
            'service_code' => 'service_code',
            'country_code' => 'country_code',
            'currency_code' => 'currency_code',
            'icon' => null, // packaged SVG tile is used unless you set a URL here
        ],

        'mollie' => [
            'is_active' => true,
            'api_key' => 'api_key',
            'base_url' => 'base_url',
            'send_webhook_url' => 'send_webhook_url',
            'icon' => null, // packaged SVG tile is used unless you set a URL here
        ],

        'mercadopago' => [
            'is_active' => true,
            'access_token' => 'access_token',
            'base_url' => 'base_url',
            'send_notification_url' => 'send_notification_url',
            'icon' => null, // packaged SVG tile is used unless you set a URL here
        ],

        'onafriq' => [
            'is_active' => true,
            'api_key' => 'api_key',
            'api_secret' => 'api_secret',
            'base_url' => 'base_url',
            'channel' => 'channel',
            'icon' => null, // packaged SVG tile is used unless you set a URL here
        ],

        'palmpay' => [
            'is_active' => true,
            'merchant_id' => 'merchant_id',
            'app_id' => 'app_id',
            'private_key' => 'private_key',
            'public_key' => 'public_key',
            'base_url' => 'base_url',
            'icon' => null, // packaged SVG tile is used unless you set a URL here
        ],

        'dpo' => [
            'is_active' => true,
            'company_token' => 'company_token',
            'base_url' => 'base_url',
            'checkout_base_url' => 'checkout_base_url',
            'service_type' => 'service_type',
            'icon' => null, // packaged SVG tile is used unless you set a URL here
        ],

        'payu' => [
            'is_active' => true,
            'merchant_key' => 'merchant_key',
            'salt' => 'salt',
            'base_url' => 'base_url',
            'icon' => null, // packaged SVG tile is used unless you set a URL here
        ],

        'cashfree' => [
            'is_active' => true,
            'client_id' => 'client_id',
            'client_secret' => 'client_secret',
            'base_url' => 'base_url',
            'api_version' => 'api_version',
            'environment' => 'environment',
            'icon' => null, // packaged SVG tile is used unless you set a URL here
        ],

        'worldpay' => [
            'is_active' => true,
            'authorization' => 'authorization',
            'merchant_entity' => 'merchant_entity',
            'narrative_line1' => 'narrative_line1',
            'base_url' => 'base_url',
            'icon' => null, // packaged SVG tile is used unless you set a URL here
        ],

        'authorizenet' => [
            'is_active' => true,
            'api_login_id' => 'api_login_id',
            'transaction_key' => 'transaction_key',
            'environment' => 'environment', // sandbox or production
            'icon' => null, // packaged SVG tile is used unless you set a URL here
        ],

        'checkout' => [
            'is_active' => true,
            'secret_key' => 'secret_key',
            'base_url' => 'base_url', // https://api.sandbox.checkout.com | https://api.checkout.com
            'icon' => null, // packaged SVG tile is used unless you set a URL here
        ],

        'telr' => [
            'is_active' => true,
            'store_id' => 'store_id',
            'auth_key' => 'auth_key',
            'test_mode' => 'test_mode', // '1' test, '0' live
            'icon' => null, // packaged SVG tile is used unless you set a URL here
        ],

        'moyasar' => [
            'is_active' => true,
            'secret_key' => 'secret_key',
            'base_url' => 'base_url', // https://api.moyasar.com
            'icon' => null, // packaged SVG tile is used unless you set a URL here
        ],

        'iyzico' => [
            'is_active' => true,
            'api_key' => 'api_key',
            'secret_key' => 'secret_key',
            'base_url' => 'base_url', // https://sandbox-api.iyzipay.com | https://api.iyzipay.com
            'icon' => null, // packaged SVG tile is used unless you set a URL here
        ],

        'ccavenue' => [
            'is_active' => true,
            'merchant_id' => 'merchant_id',
            'access_code' => 'access_code',
            'working_key' => 'working_key',
            'base_url' => 'base_url', // https://test.ccavenue.com | https://secure.ccavenue.com
            'icon' => null, // packaged SVG tile is used unless you set a URL here
        ],

        'paytm' => [
            'is_active' => true,
            'merchant_id' => 'merchant_id',
            'merchant_key' => 'merchant_key',
            'website' => 'website', // WEBSTAGING (stage) | DEFAULT (live)
            'base_url' => 'base_url', // https://securegw-stage.paytm.in | https://securegw.paytm.in
            'icon' => null, // packaged SVG tile is used unless you set a URL here
        ],

        'payhere' => [
            'is_active' => true,
            'merchant_id' => 'merchant_id',
            'merchant_secret' => 'merchant_secret',
            'base_url' => 'base_url', // https://sandbox.payhere.lk | https://www.payhere.lk
            'icon' => null, // packaged SVG tile is used unless you set a URL here
        ],

        'fawry' => [
            'is_active' => true,
            'merchant_code' => 'merchant_code',
            'secure_key' => 'secure_key',
            'base_url' => 'base_url', // https://atfawry.fawrystaging.com | https://www.atfawry.com
            'icon' => null, // packaged SVG tile is used unless you set a URL here
        ],

        'payfort' => [
            'is_active' => true,
            'access_code' => 'access_code',
            'merchant_identifier' => 'merchant_identifier',
            'sha_request_phrase' => 'sha_request_phrase',
            'base_url' => 'base_url', // https://sbpaymentservices.payfort.com | https://paymentservices.payfort.com
            'checkout_base_url' => 'checkout_base_url', // https://sbcheckout.payfort.com | https://checkout.payfort.com
            'icon' => null, // packaged SVG tile is used unless you set a URL here
        ],

        'hyperpay' => [
            'is_active' => true,
            'access_token' => 'access_token',
            'entity_id' => 'entity_id',
            'base_url' => 'base_url', // https://eu-test.oppwa.com | https://eu-prod.oppwa.com
            'brands' => 'brands', // optional, e.g. "VISA MASTER MADA"
            'icon' => null, // packaged SVG tile is used unless you set a URL here
        ],

        'ngenius' => [
            'is_active' => true,
            'api_key' => 'api_key',
            'outlet_ref' => 'outlet_ref',
            'base_url' => 'base_url', // https://api-gateway.sandbox.ngenius-payments.com | https://api-gateway.ngenius-payments.com
            'icon' => null, // packaged SVG tile is used unless you set a URL here
        ],

        'twocheckout' => [
            'is_active' => true,
            'merchant_code' => 'merchant_code',
            'secret_key' => 'secret_key',
            'buy_link_secret_word' => 'buy_link_secret_word',
            'icon' => null, // packaged SVG tile is used unless you set a URL here
        ],

        'voguepay' => [
            'is_active' => true,
            'merchant_id' => 'merchant_id',
            'developer_code' => 'developer_code', // optional
            'demo' => 'demo', // optional, true for demo mode
            'icon' => null, // packaged SVG tile is used unless you set a URL here
        ],

        'payfast' => [
            'is_active' => true,
            'merchant_id' => 'merchant_id',
            'merchant_key' => 'merchant_key',
            'passphrase' => 'passphrase', // optional — required only if set on the PayFast account
            'base_url' => 'base_url', // https://sandbox.payfast.co.za | https://www.payfast.co.za
            'icon' => null, // packaged SVG tile is used unless you set a URL here
        ],

        'fygaro' => [
            'is_active' => true,
            'button_url' => 'button_url', // your payment button's URL from the Fygaro dashboard
            'api_key' => 'api_key',
            'secret_key' => 'secret_key',
            'icon' => null, // packaged SVG tile is used unless you set a URL here
        ],

        'toppay' => [
            'is_active' => true,
            'merchant_id' => 'merchant_id',
            'api_key' => 'api_key',
            'base_url' => 'base_url',
            'icon' => null, // packaged SVG tile is used unless you set a URL here
        ],

        'stepay' => [
            'is_active' => true,
            'merchant_id' => 'merchant_id',
            'api_key' => 'api_key',
            'base_url' => 'base_url',
            'icon' => null, // packaged SVG tile is used unless you set a URL here
        ],
    ]
];
