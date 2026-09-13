<?php

use Illuminate\Support\Facades\Route;
use Abedin\MultiPay\Http\Controllers\BraintreeCheckoutController;
use Abedin\MultiPay\Http\Controllers\CashfreeCheckoutController;
use Abedin\MultiPay\Http\Controllers\FormPostCheckoutController;
use Abedin\MultiPay\Http\Controllers\FygaroBridgeController;
use Abedin\MultiPay\Http\Controllers\GatewayAdminController;
use Abedin\MultiPay\Http\Controllers\HyperpayCheckoutController;
use Abedin\MultiPay\Http\Controllers\MobileMoneyCheckoutController;
use Abedin\MultiPay\Http\Controllers\PayuCheckoutController;

/*
|--------------------------------------------------------------------------
| Internal hosted-checkout routes only
|--------------------------------------------------------------------------
|
| success / cancel / failure / callback belong to the HOST APP — it registers
| them under the names in config('multipay.routes') and calls
| MultiPay::confirm() there. The package keeps only the pages that are pure
| gateway plumbing: Braintree's drop-in form, Cashfree's JS redirect and
| PayU's signed form post.
|
*/
Route::group([
    'prefix' => config('multipay.internal_routes.prefix', 'multipay'),
    'as' => config('multipay.internal_routes.as', 'multipay.internal.'),
    'middleware' => ['web'],
], function () {
    Route::get('/braintree/{session}/checkout', [BraintreeCheckoutController::class, 'show'])->name('braintree.checkout');
    Route::post('/braintree/{session}/checkout', [BraintreeCheckoutController::class, 'process'])->name('braintree.process');
    Route::get('/cashfree/{session}/checkout', [CashfreeCheckoutController::class, 'show'])->name('cashfree.checkout');
    Route::get('/payu/{session}/checkout', [PayuCheckoutController::class, 'show'])->name('payu.checkout');
    Route::get('/form/{session}/checkout', [FormPostCheckoutController::class, 'show'])->name('form.checkout');
    Route::get('/hyperpay/{session}/checkout', [HyperpayCheckoutController::class, 'show'])->name('hyperpay.checkout');
    Route::get('/mobile-money/{session}/checkout', [MobileMoneyCheckoutController::class, 'show'])->name('mobile-money.checkout');
    Route::get('/mobile-money/{session}/status', [MobileMoneyCheckoutController::class, 'status'])->name('mobile-money.status');
});

/*
|--------------------------------------------------------------------------
| Fygaro static bridge
|--------------------------------------------------------------------------
|
| Fygaro's button settings take ONE fixed return URL and ONE fixed hook URL.
| Point them here; the bridge resolves the session from custom_reference.
| No 'web' middleware: the hook is a server-to-server JSON POST (no session,
| no CSRF token) and the return is a plain redirect.
|
*/
Route::group([
    'prefix' => config('multipay.internal_routes.prefix', 'multipay'),
    'as' => config('multipay.internal_routes.as', 'multipay.internal.'),
], function () {
    Route::get('/fygaro/return', [FygaroBridgeController::class, 'return'])->name('fygaro.return');
    Route::post('/fygaro/hook', [FygaroBridgeController::class, 'hook'])->name('fygaro.hook');
});

/*
|--------------------------------------------------------------------------
| Admin endpoints (behind the includable gateway-manager blade)
|--------------------------------------------------------------------------
|
| Protect these with YOUR admin middleware via multipay.admin_middleware —
| they edit live payment credentials.
|
*/
Route::group([
    'prefix' => config('multipay.internal_routes.prefix', 'multipay') . '/admin',
    'as' => config('multipay.internal_routes.as', 'multipay.internal.') . 'admin.',
    'middleware' => config('multipay.admin_middleware', ['web', 'auth']),
], function () {
    Route::post('/gateways/{gateway}/toggle', [GatewayAdminController::class, 'toggle'])->name('gateways.toggle');
    Route::post('/gateways/{gateway}', [GatewayAdminController::class, 'update'])->name('gateways.update');
});
