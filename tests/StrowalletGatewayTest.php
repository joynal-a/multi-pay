<?php

namespace Abedin\MultiPay\Tests;

use Abedin\MultiPay\Exceptions\InvalidPaymentPayloadException;
use Abedin\MultiPay\Facades\MultiPay;
use Abedin\MultiPay\Models\PaymentSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class StrowalletGatewayTest extends TestCase
{
    private const CONFIG = ['public_key' => 'pub-key'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerHostAppRoutes();
    }

    private function fakeCheckout(string $reference = 'REF-1', ?array $status = null, int $statusCode = 200): void
    {
        Http::fake([
            'strowallet.com/pay' => Http::response([
                'status' => true,
                'message' => 'Checkout initialized successfully',
                'data' => [
                    'reference' => $reference,
                    'amount' => 100,
                    'currency' => 'NGN',
                    'status' => 'pending',
                    'checkout_url' => 'https://strowallet.com/pay/' . $reference,
                ],
            ], 200),
            'strowallet.com/api/checkout/status*' => Http::response($status ?? [], $statusCode),
        ]);
    }

    private function pay(float $amount = 100, string $currency = 'NGN'): array
    {
        return MultiPay::gateway('strowallet', self::CONFIG)->pay([
            'amount' => $amount,
            'currency' => $currency,
            'order_id' => 'ORD-1',
            'customer' => ['name' => 'Ada Obi', 'email' => 'ada@example.com'],
            'meta' => ['description' => 'Wallet top-up'],
        ]);
    }

    private function statusBody(string $paymentStatus, string $amount = '100.00', string $currency = 'NGN', string $reference = 'REF-1'): array
    {
        return [
            'status' => true,
            'message' => 'Payment status fetched successfully.',
            'data' => [
                'reference' => $reference,
                'amount' => $amount,
                'currency' => $currency,
                'payment_status' => $paymentStatus,
            ],
        ];
    }

    public function test_pay_creates_checkout_and_returns_hosted_url(): void
    {
        $this->fakeCheckout();

        $result = $this->pay(100.5);
        $session = PaymentSession::first();

        $this->assertTrue($result['success']);
        $this->assertSame('REF-1', $result['payment_id']);
        $this->assertSame('https://strowallet.com/pay/REF-1', $result['payment_url']);
        $this->assertSame('REF-1', $session->payment_id);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://strowallet.com/pay'
            && $request['public_key'] === 'pub-key'
            && $request['amount'] === '100.50'
            && $request['currency'] === 'NGN'
            && $request['description'] === 'Wallet top-up'
            && $request['customer_name'] === 'Ada Obi'
            && $request['customer_email'] === 'ada@example.com'
            && str_ends_with($request['success_url'], '/payment/' . $session->session_id . '/success')
            && str_ends_with($request['cancel_url'], '/payment/' . $session->session_id . '/cancel')
            && str_ends_with($request['callback_url'], '/payment/' . $session->session_id . '/callback'));
    }

    public function test_pay_rejects_unsupported_currency(): void
    {
        $this->expectException(InvalidPaymentPayloadException::class);
        $this->expectExceptionMessage('StroWallet only accepts NGN or USD');

        $this->pay(10, 'EUR');
    }

    public function test_pay_surfaces_gateway_error(): void
    {
        Http::fake([
            'strowallet.com/pay' => Http::response(['status' => false, 'message' => 'Invalid public key'], 404),
        ]);

        $this->expectExceptionMessage('StroWallet Payment Initialization Failed');

        $this->pay();
    }

    public function test_confirm_marks_paid_when_status_api_says_paid(): void
    {
        $this->fakeCheckout('REF-1', $this->statusBody('paid'));
        $this->pay();
        $session = PaymentSession::first();

        $result = MultiPay::confirm($session->session_id, Request::create('/x'), self::CONFIG);

        $this->assertTrue($result->success);
        $this->assertSame('paid', $session->fresh()->status);

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://strowallet.com/api/checkout/status')
            && $request['public_key'] === 'pub-key'
            && $request['reference'] === 'REF-1');
    }

    public function test_pending_payment_returned_as_http_400_stays_pending(): void
    {
        $this->fakeCheckout('REF-1', $this->statusBody('pending'), 400);
        $this->pay();

        $result = MultiPay::confirm(PaymentSession::first()->session_id, Request::create('/x'), self::CONFIG);

        $this->assertFalse($result->success);
        $this->assertSame('pending', $result->status);
    }

    public function test_failed_payment_is_marked_failed(): void
    {
        $this->fakeCheckout('REF-1', $this->statusBody('failed'));
        $this->pay();

        $result = MultiPay::confirm(PaymentSession::first()->session_id, Request::create('/x'), self::CONFIG);

        $this->assertFalse($result->success);
        $this->assertSame('failed', PaymentSession::first()->status);
    }

    public function test_paid_with_lower_amount_or_other_currency_is_rejected(): void
    {
        $this->fakeCheckout('REF-1', $this->statusBody('paid', '50.00'));
        $this->pay();

        $result = MultiPay::confirm(PaymentSession::first()->session_id, Request::create('/x'), self::CONFIG);

        $this->assertFalse($result->success);
        $this->assertSame('failed', $result->status);
    }

    public function test_callback_query_reference_is_not_trusted(): void
    {
        $this->fakeCheckout('REF-1', $this->statusBody('pending'), 400);
        $this->pay();

        $callback = Request::create('/x', 'GET', ['reference' => 'SOMEONE-ELSES-PAID-REF', 'status' => 'paid']);
        $result = MultiPay::confirm(PaymentSession::first()->session_id, $callback, self::CONFIG);

        $this->assertFalse($result->success);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request['reference'] === 'REF-1');
        Http::assertNotSent(fn ($request) => $request->method() === 'GET' && $request['reference'] === 'SOMEONE-ELSES-PAID-REF');
    }

    public function test_invalid_key_on_lookup_stays_pending(): void
    {
        $this->fakeCheckout('REF-1', ['status' => false, 'message' => 'Invalid Public key'], 404);
        $this->pay();

        $result = MultiPay::confirm(PaymentSession::first()->session_id, Request::create('/x'), self::CONFIG);

        $this->assertFalse($result->success);
        $this->assertSame('pending', $result->status);
        $this->assertStringContainsString('Invalid Public key', $result->message);
    }
}
