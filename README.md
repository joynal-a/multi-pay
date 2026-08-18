# MultiPay

**One body, any gateway. One confirm, any gateway.**

MultiPay is a Laravel package that lets you accept payments through **30+ payment gateways** with a single, stable API. Your application sends the same normalized payload to every gateway and gets back a hosted payment URL; when the customer returns, one call verifies the payment **server-side with the gateway itself** — no trusting redirects.

```php
// 1. Same payload for every gateway — only the name changes
$result = MultiPay::gateway('stripe')->pay([
    'amount' => 100,
    'currency' => 'USD',
    'order_id' => 'ORD-1001',
    'customer' => ['name' => 'John Doe', 'email' => 'john@example.com'],
]);

return redirect($result['payment_url']);
```

```php
// 2. When the customer comes back, verify with one call
$result = MultiPay::confirm($session, $request);

if ($result->success) {
    // money confirmed by the gateway — fulfil the order
}
```

---

## Table of contents

- [Features](#features)
- [Supported gateways](#supported-gateways)
- [Requirements](#requirements)
- [Installation](#installation)
- [Setup](#setup)
  - [1. Publish config & migrations](#1-publish-config--migrations)
  - [2. Register your routes](#2-register-your-routes)
  - [3. Exclude the routes from CSRF](#3-exclude-the-routes-from-csrf)
  - [4. Store gateway credentials](#4-store-gateway-credentials)
- [Usage](#usage)
  - [Creating a payment](#creating-a-payment)
  - [The payload](#the-payload)
  - [The response](#the-response)
  - [Verifying a payment — confirm()](#verifying-a-payment--confirm)
  - [Session statuses](#session-statuses)
  - [Events](#events)
  - [Helpers — session() and cancel()](#helpers--session-and-cancel)
- [Testing your integration (demo gateway)](#testing-your-integration-demo-gateway)
- [Gateway configuration reference](#gateway-configuration-reference)
- [How verification works](#how-verification-works)
- [Hosted checkout pages](#hosted-checkout-pages)
- [Amounts and currencies](#amounts-and-currencies)
- [Updating the package](#updating-the-package)
- [Adding a new gateway](#adding-a-new-gateway)
- [Running the package tests](#running-the-package-tests)
- [Status & roadmap](#status--roadmap)
- [License](#license)

---

## Features

- **30+ gateways, one API** — normalized request in, normalized response out.
- **Server-side verification** — `confirm()` asks the gateway whether the money actually moved (API query by stored reference, signature validation, or payload decryption). A redirect alone never marks anything paid.
- **You own the routes** — success / cancel / failure / callback endpoints live in *your* app, with *your* business logic. The package only needs their route names.
- **Payment sessions** — every attempt is tracked in a `payment_sessions` table, keyed by a non-guessable UUID.
- **Events** — `PaymentSucceeded` / `PaymentFailed` are dispatched on verification, so you can fulfil orders from listeners.
- **Runtime credentials** — gateway keys live in your database (JSON column), so admins can change them without deploying.
- **Idempotent confirm** — a session verified as paid stays paid; calling `confirm()` from both the redirect and the webhook is safe.
- **Demo gateway** — exercise the full pay → redirect → confirm loop locally with zero credentials.

## Supported gateways

| Key | Gateway | Region / notes |
| --- | --- | --- |
| `stripe` | Stripe Checkout | Global |
| `razorpay` | Razorpay Payment Links | India |
| `paystack` | Paystack | Africa |
| `paytabs` | PayTabs | MENA |
| `adyen` | Adyen Payment Links | Global |
| `square` | Square Payment Links | US, global |
| `braintree` | Braintree (drop-in) | Global |
| `flutterwave` | Flutterwave | Africa |
| `hesabe` | Hesabe | Kuwait |
| `tingg` | Tingg (Cellulant) | Africa |
| `mollie` | Mollie | Europe |
| `mercadopago` | Mercado Pago | Latin America |
| `dpo` | DPO Group | Africa |
| `payu` | PayU (hosted form) | India |
| `cashfree` | Cashfree | India |
| `worldpay` | Worldpay Hosted Payment Pages | Global |
| `authorizenet` | Authorize.Net Accept Hosted | US |
| `checkout` | Checkout.com Hosted Payments | Global |
| `telr` | Telr | MENA |
| `moyasar` | Moyasar | Saudi Arabia |
| `iyzico` | iyzico Checkout Form | Turkey |
| `ccavenue` | CCAvenue | India |
| `paytm` | Paytm | India |
| `payhere` | PayHere | Sri Lanka |
| `fawry` | FawryPay | Egypt |
| `payfort` | Amazon Payment Services (PayFort) | MENA |
| `hyperpay` | HyperPay (COPYandPAY) | MENA |
| `ngenius` | N-Genius (Network International) | MENA |
| `twocheckout` | 2Checkout (Verifone) | Global |
| `voguepay` | VoguePay | Nigeria |
| `payfast` | PayFast | South Africa |
| `fygaro` | Fygaro | Caribbean / Latin America |
| `demo` | Demo gateway | Local testing only — never enable in production |
| `onafriq`, `palmpay`, `toppay`, `stepay` | Scaffolds | Registered but not implemented (no public API docs); they throw a clear exception |

## Requirements

- PHP 8.2+
- Laravel 10, 11, 12 or 13
- PHP extensions: `openssl`, `json` (used by several gateways), `simplexml` (DPO)

## Installation

```bash
composer require joynala/multi-pay
```

If you are installing from a private/self-hosted git repository instead of Packagist, add the repository to your app's `composer.json` first:

```json
"repositories": [
    { "type": "vcs", "url": "git@github.com:joynal-a/multi-pay.git" }
]
```

The service provider and the `MultiPay` facade are auto-discovered.

## Setup

### 1. Publish config & migrations

```bash
php artisan vendor:publish --tag=multi-pay
php artisan migrate
```

This publishes `config/multipay.php` and creates the `payment_sessions` table.

> Tags are also available individually: `--tag=multi-pay-config`, `--tag=multi-pay-migrations`.

### 2. Register your routes

MultiPay deliberately does **not** own your success / cancel / failure / callback endpoints — only your app knows which order to update and where to send the customer next. Register one common set of routes (it covers **all** gateways; the `{session}` UUID in the URL tells the package which gateway and payment it was):

```php
// routes/web.php
use App\Http\Controllers\PaymentController;

Route::match(['get', 'post'], '/payment/{session}/success', [PaymentController::class, 'success'])->name('multipay.success');
Route::match(['get', 'post'], '/payment/{session}/cancel',  [PaymentController::class, 'cancel'])->name('multipay.cancel');
Route::match(['get', 'post'], '/payment/{session}/failure', [PaymentController::class, 'failure'])->name('multipay.failure');
Route::match(['get', 'post'], '/payment/{session}/callback', [PaymentController::class, 'callback'])->name('multipay.callback');
```

Notes:

- Use `Route::match(['get','post'], ...)` — some gateways return the customer with GET, others POST a form (PayU, PayHere, CCAvenue...).
- The route **names** are the contract. If you want different names or URIs, change the names in `config/multipay.php` → `routes` and keep them in sync.
- `pending`, `error` and `expiry` names also exist in the config for gateways that use them (Worldpay, Mercado Pago). If you don't register those routes, the package automatically falls back: `pending → success`, `error`/`expiry → failure`.
- If a required route is missing, `pay()` throws a `MissingRouteException` that tells you exactly which name to register.

### 3. Exclude the routes from CSRF

Gateways POST to your routes without a CSRF token.

**Laravel 11+** — `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->validateCsrfTokens(except: ['payment/*']);
})
```

**Laravel 10** — add `'payment/*'` to `$except` in `App\Http\Middleware\VerifyCsrfToken`.

### 4. Store gateway credentials

Credentials live in your **database** so they can be changed at runtime (e.g. from an admin panel). By default the package reads the `gateways` table, `data` JSON column — both configurable in `config/multipay.php` (`data_table`, `json_column`).

Each gateway needs one row: `name` = the gateway key, and the JSON column holding its credentials. `config/multipay.php` → `gateways` maps the package's required key names to *your* JSON field names, so you can keep whatever JSON shape you already have.

```php
// config/multipay.php
'stripe' => [
    'is_active' => true,
    'secret_key' => 'secret_key_data',   // package's "secret_key" lives in JSON field "secret_key_data"
    'public_key' => 'public_key_data',
],
```

```sql
INSERT INTO gateways (name, data) VALUES
('stripe', '{"secret_key_data": "sk_test_...", "public_key_data": "pk_test_..."}');
```

Setting `is_active => false` disables a gateway — `gateway()` will refuse to build it.

**Skipping the database** — pass credentials directly (uses the package's own key names; handy for tests):

```php
MultiPay::gateway('stripe', ['secret_key' => 'sk_test_...', 'public_key' => 'pk_test_...']);
```

## Usage

### Creating a payment

```php
use Abedin\MultiPay\Facades\MultiPay;

$result = MultiPay::gateway('razorpay')->pay([
    'amount' => 499.00,
    'currency' => 'INR',
    'order_id' => 'ORD-' . $order->id,
    'customer' => [
        'name' => $user->name,
        'email' => $user->email,
    ],
    'meta' => [
        'title' => 'Pro Plan',
        'description' => 'Pro plan subscription',
        'phone' => $user->phone,
    ],
]);

return redirect($result['payment_url']);
```

> **Use a unique `order_id` per payment attempt.** Several gateways use it as the merchant reference, and verification matches against it.

### The payload

| Field | Required | Description |
| --- | --- | --- |
| `amount` | yes | Major units — `19.99` means $19.99. Minor-unit conversion (cents, paise, fils) is handled per gateway and per currency. |
| `currency` | yes | ISO 4217 code (`USD`, `INR`, `KWD`, ...). |
| `order_id` | yes | Your unique reference for this payment attempt. |
| `customer.name` | no | Customer name. |
| `customer.email` | no | Customer email (some gateways require it — Paystack does). |
| `meta.*` | no | Gateway extras: `title`, `description`, `phone`, `address`, `city`, `country`, `locale`, ... Unknown keys are ignored by gateways that don't use them. |

A missing required field throws `InvalidPaymentPayloadException` before anything is sent anywhere.

### The response

`pay()` returns an array:

```php
[
    'success'         => true,
    'gateway'         => 'razorpay',
    'payment_id'      => 'plink_...',      // gateway-side reference, stored on the session
    'payment_url'     => 'https://...',    // send the customer here
    'status'          => 'pending',
    'supports_iframe' => false,
    'message'         => 'Razorpay payment link generated successfully.',
    'raw'             => [...],            // full gateway response
]
```

A `payment_sessions` row is created for every attempt with a UUID `session_id` — that UUID is what appears in your route URLs.

### Verifying a payment — confirm()

A redirect to your success URL proves nothing: anyone can open that URL. **Always call `confirm()` first** — it performs the gateway-specific server-side check, updates the session, fires the events, and returns a normalized result object:

```php
class PaymentController extends Controller
{
    public function success(Request $request, string $session)
    {
        $result = MultiPay::confirm($session, $request);

        if (!$result->success) {
            return redirect()->route('checkout')->with('error', $result->message);
        }

        Order::where('order_no', MultiPay::session($session)->order_id)
            ->update(['status' => 'paid']);

        return redirect()->route('orders.thankyou');
    }

    public function callback(Request $request, string $session) // server-to-server
    {
        MultiPay::confirm($session, $request);

        return response()->json(['ok' => true]);
    }

    public function cancel(Request $request, string $session)
    {
        MultiPay::cancel($session);

        return redirect()->route('checkout')->with('error', 'Payment cancelled.');
    }

    public function failure(Request $request, string $session)
    {
        $result = MultiPay::confirm($session, $request); // records the failure with gateway proof

        return redirect()->route('checkout')->with('error', 'Payment failed.');
    }
}
```

`confirm()` accepts the session UUID (from the route parameter) or a `PaymentSession` model, plus the current `Request` (some gateways deliver verification material in it — PayU's signed POST, Hesabe's encrypted payload). It returns a `PaymentResponseData` object with the same fields as `pay()`'s array, as properties (`$result->success`, `$result->status`, `$result->paymentId`, `$result->message`, `$result->raw`).

`confirm()` is **idempotent**: once a session is `paid` it returns immediately without re-querying the gateway, so calling it from both the browser return and the webhook is safe.

### Session statuses

| Status | Meaning |
| --- | --- |
| `pending` | Created / awaiting payment or awaiting verification |
| `paid` | Verified with the gateway — money moved |
| `failed` | Gateway reported failure, or verification rejected the response |
| `cancelled` | Customer cancelled, or `MultiPay::cancel()` was called |
| `expired` | The gateway's payment window expired |

### Events

Verification dispatches:

- `Abedin\MultiPay\Events\PaymentSucceeded` — properties: `PaymentSession $session`, `PaymentResponseData $result`
- `Abedin\MultiPay\Events\PaymentFailed` — same properties

```php
// A listener is a clean place for fulfilment logic:
class FulfilOrder
{
    public function handle(PaymentSucceeded $event): void
    {
        Order::where('order_no', $event->session->order_id)->update(['status' => 'paid']);
    }
}
```

### Helpers — session() and cancel()

```php
$session = MultiPay::session($uuid);   // fetch the PaymentSession (throws PaymentSessionNotFoundException)
MultiPay::cancel($uuid);               // mark cancelled — only affects sessions still 'pending'; never downgrades a paid session
```

## Gateway manager UI (includable Blade)

MultiPay ships a drop-in admin screen for managing gateways — list, active/inactive
toggle, and a credentials modal per gateway. Include it anywhere inside your own
admin layout; it brings its own styles and JavaScript (no build step, no
dependencies):

```blade
{{-- resources/views/admin/payment-gateways.blade.php --}}
@extends('admin.layout')

@section('content')
    @include('joynala.multi-pay::admin.gateways')
@endsection
```

What it does:

- **Lists every registered gateway** with its status: *Active*, *Inactive*, or *Credentials missing*.
- **Toggle** — flips the gateway's runtime switch. The switch is stored as `is_active` inside the gateway's JSON credentials row, so it applies instantly without a deploy. Activation is blocked (with a clear message) until the required credentials are filled.
- **Update** — opens a modal with exactly the credential fields that gateway needs (driven by your `config/multipay.php` mapping) plus an optional **logo URL** field, and saves them to the database. A saved logo URL overrides the packaged icon everywhere.

⚠️ **Protect it.** The UI's endpoints edit live payment credentials. Set your admin
middleware in `config/multipay.php`:

```php
'admin_middleware' => ['web', 'auth', 'can:manage-payments'], // default: ['web', 'auth']
```

Notes:

- Include the blade only on pages already behind that same admin protection.
- The `is_active` flag in the **config file** is a developer-level master switch; the DB flag managed by this UI is the runtime switch. A gateway runs only when neither is off.
- If your app doesn't have a gateways table yet, the package migration creates one (`name` + JSON `data` + timestamps) — it never touches an existing table, whatever names you configured.

## Listing active gateways (for your checkout page or API)

`MultiPay::activeGateways()` returns only the gateways that are switched on,
with everything needed to render a payment-method picker — in Blade, or as a
JSON API response for your mobile apps:

```php
Route::get('/api/payment-methods', fn () => MultiPay::activeGateways());
```

```json
[
    {
        "name": "stripe",
        "label": "Stripe",
        "icon": "https://your-site.com/images/stripe.png",
        "icon_svg": "<svg ...>...</svg>",
        "icon_data_uri": "data:image/svg+xml;base64,..."
    }
]
```

- `icon` — the logo URL set from the admin UI (or config); `null` when none is set.
- `icon_svg` / `icon_data_uri` — the packaged SVG, inline markup or a ready-to-use `<img src>` value.

Display logic: use `icon` when present, otherwise `icon_data_uri`.

```blade
@foreach (MultiPay::activeGateways() as $g)
    <label>
        <input type="radio" name="gateway" value="{{ $g['name'] }}">
        <img src="{{ $g['icon'] ?? $g['icon_data_uri'] }}" alt="{{ $g['label'] }}" height="28">
        {{ $g['label'] }}
    </label>
@endforeach
```

## Testing your integration (demo gateway)

The `demo` gateway needs **no credentials and no database row**:

- `pay()` returns a `payment_url` that goes straight to your success route;
- `confirm()` always verifies as paid.

Point your checkout at `MultiPay::gateway('demo')` and you can exercise the entire loop — payload validation, session creation, your routes, `confirm()`, events — locally in seconds.

> ⚠️ **Never enable `demo` in production.** Anyone who can choose the gateway name would be able to "pay" for free. Set `'demo' => ['is_active' => false]` in your published config before going live.

## Gateway configuration reference

Keys listed are the package's names; map each to your JSON field in `config/multipay.php`. "Base URL" rows show sandbox → live.

| Gateway | Required keys | Base URLs (sandbox → live) |
| --- | --- | --- |
| `stripe` | `secret_key`, `public_key` | SDK-managed |
| `razorpay` | `secret_key`, `public_key` | SDK-managed |
| `paystack` | `secret_key` | SDK-managed |
| `paytabs` | `profile_id`, `secret_key`, `base_url` | region-specific, e.g. `https://secure-global.paytabs.com` |
| `adyen` | `api_key`, `merchant_account`, `country_code` | see [Status](#status--roadmap) — live env pending |
| `square` | `square_access_token`, `square_location`, `square_environment` | `square_environment`: `sandbox` / `production` |
| `braintree` | `environment`, `merchant_id`, `public_key`, `private_key` | `environment`: `sandbox` / `production` |
| `flutterwave` | `secret_key`, `base_url` | `https://api.flutterwave.com` |
| `hesabe` | `merchant_code`, `access_code`, `encryption_key`, `iv_key` | `https://sandbox.hesabe.com` → `https://api.hesabe.com` (via `mode`) |
| `tingg` | `api_key`, `client_id`, `client_secret`, `auth_base_url`, `base_url`, `service_code`, `country_code`, `currency_code` | per Tingg onboarding |
| `mollie` | `api_key`, `base_url`, `send_webhook_url` | `https://api.mollie.com` |
| `mercadopago` | `access_token`, `base_url`, `send_notification_url` | `https://api.mercadopago.com` |
| `dpo` | `company_token`, `base_url`, `checkout_base_url`, `service_type` | `https://secure.3gdirectpay.com` |
| `payu` | `merchant_key`, `salt`, `base_url` | `https://test.payu.in` → `https://secure.payu.in` |
| `cashfree` | `client_id`, `client_secret`, `base_url`, `api_version`, `environment` | `https://sandbox.cashfree.com` → `https://api.cashfree.com` |
| `worldpay` | `authorization`, `merchant_entity`, `narrative_line1`, `base_url` | `https://try.access.worldpay.com` → `https://access.worldpay.com` |
| `authorizenet` | `api_login_id`, `transaction_key`, `environment` | `environment`: `sandbox` / `production` |
| `checkout` | `secret_key`, `base_url` | `https://api.sandbox.checkout.com` → `https://api.checkout.com` |
| `telr` | `store_id`, `auth_key` (+ `test_mode`: `'1'` test / `'0'` live) | fixed endpoint |
| `moyasar` | `secret_key`, `base_url` | `https://api.moyasar.com` |
| `iyzico` | `api_key`, `secret_key`, `base_url` | `https://sandbox-api.iyzipay.com` → `https://api.iyzipay.com` |
| `ccavenue` | `merchant_id`, `access_code`, `working_key`, `base_url` | `https://test.ccavenue.com` → `https://secure.ccavenue.com` |
| `paytm` | `merchant_id`, `merchant_key`, `website`, `base_url` | `https://securegw-stage.paytm.in` → `https://securegw.paytm.in`; `website`: `WEBSTAGING` → `DEFAULT` |
| `payhere` | `merchant_id`, `merchant_secret`, `base_url` | `https://sandbox.payhere.lk` → `https://www.payhere.lk` |
| `fawry` | `merchant_code`, `secure_key`, `base_url` | `https://atfawry.fawrystaging.com` → `https://www.atfawry.com` |
| `payfort` | `access_code`, `merchant_identifier`, `sha_request_phrase`, `base_url`, `checkout_base_url` | `sbpaymentservices`/`sbcheckout` → `paymentservices`/`checkout` `.payfort.com` |
| `hyperpay` | `access_token`, `entity_id`, `base_url` (+ optional `brands`) | `https://eu-test.oppwa.com` → `https://eu-prod.oppwa.com` |
| `ngenius` | `api_key`, `outlet_ref`, `base_url` | `https://api-gateway.sandbox.ngenius-payments.com` → `https://api-gateway.ngenius-payments.com` |
| `twocheckout` | `merchant_code`, `secret_key`, `buy_link_secret_word` | fixed endpoints |
| `voguepay` | `merchant_id` (+ optional `developer_code`, `demo`) | fixed endpoint |
| `payfast` | `merchant_id`, `merchant_key`, `base_url` (+ optional `passphrase`) | `https://sandbox.payfast.co.za` → `https://www.payfast.co.za` |
| `fygaro` | `button_url`, `api_key`, `secret_key` | button URL from your Fygaro dashboard; set the button's Return URL to `https://your-app.com/multipay/fygaro/return` and Hook URL to `https://your-app.com/multipay/fygaro/hook` (JWT features need the Pro plan) |

## How verification works

`confirm()` never trusts the customer's browser. Depending on the gateway it:

- **queries the gateway API** using the reference stored at initiation — Stripe (checkout session), Razorpay (payment link), Paystack, PayTabs, Mollie, Moyasar, Checkout.com, Cashfree, Fawry, Paytm, Telr, N-Genius, HyperPay, 2Checkout, Worldpay, Mercado Pago, Flutterwave, DPO, Adyen, Square, Braintree, Authorize.Net, Tingg, VoguePay; **or**
- **validates a cryptographic proof** carried in the request — PayU (SHA-512 reverse hash), PayHere (signed notify), CCAvenue (AES-decrypted response), Hesabe (AES-decrypted payload).

Signature comparisons use `hash_equals`; verifications match order reference and amount where the gateway returns them.

## Hosted checkout pages

Some gateway flows need a browser-side step that is pure gateway plumbing — a drop-in form, a JS widget, or a signed form POST. Those pages ship inside the package under the `multipay/` URL prefix (configurable via `internal_routes` in the config):

- **Braintree** — drop-in UI page
- **Cashfree** — checkout JS redirect page
- **PayU, CCAvenue, Paytm, PayFort, PayHere** — one shared auto-submitting signed form page
- **HyperPay** — COPYandPAY widget page

You don't call these directly; `pay()` returns their URL as the `payment_url` when applicable.

## Amounts and currencies

You always pass **major units** (`19.99`). Each gateway adapter converts to what its API expects — minor units (cents/paise/fils) or formatted decimal strings — using the correct ISO 4217 exponent: 0-decimal currencies (JPY, KRW, ...), 3-decimal currencies (KWD, BHD, OMR, ...), and 2-decimal for the rest. Float-precision bugs (`19.99 * 100 = 1998.99...`) are handled by rounding.

## Updating the package

After `composer update joynala/multi-pay`, re-publish if the release notes mention config or migration changes:

```bash
php artisan vendor:publish --tag=multi-pay-config --force
php artisan vendor:publish --tag=multi-pay-migrations --force
php artisan migrate
```

> Published files are copies — they do **not** update automatically with the package.

## Adding a new gateway

1. Create `src/Services/YourGateway.php` extending `BaseGateway` and implement:
   - `needyConfig(): array` — required credential keys;
   - `requestPayment(PaymentRequestData $payment): PaymentResponseData` — call the gateway, return the payment URL (use `successUrl()` / `cancelUrl()` / `callbackUrl()` for return URLs, `minorAmount()` for amounts, or `formPostResponse()` if the gateway needs a signed browser POST);
   - `verifyPayment(PaymentSession $session, Request $request): PaymentResponseData` — prove the money moved server-side; return via `verified()` / `unverified()`.
2. Register it in `src/Managers/BaseManager.php` (`$gateways`), add a case to `src/Enums/Gateways.php`, and a config section in `Config/multipay.php`.
3. Run `vendor/bin/phpunit` — `RegistryConsistencyTest` enforces that registry, enum, and config stay in sync.

PRs adding gateways are welcome — please include sandbox test notes.

## Running the package tests

```bash
composer install
vendor/bin/phpunit
```

## Status & roadmap

- The newer gateway adapters are implemented against each provider's documented API but not all have been exercised against live sandboxes yet — **run one sandbox transaction with your credentials before going live**, and open an issue if anything mismatches.
- `adyen` currently targets Adyen's **test** environment; live-environment support (live URL prefix) is on the roadmap — do not use it in production yet.
- `onafriq`, `palmpay`, `toppay`, `stepay` are scaffolds: registered and configurable, but they throw an explanatory exception until their (non-public) API documentation is available. Set them `is_active => false`.
- On the roadmap: row-level locking around `confirm()` for high-concurrency setups, a `PaymentPending` event distinct from `PaymentFailed`, webhook signature helpers, and refunds.

## License

MIT — see [LICENSE](LICENSE). Built by [Joynal Abedin](mailto:abedin.dev@gmail.com).
