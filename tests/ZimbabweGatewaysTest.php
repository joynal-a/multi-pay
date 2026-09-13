<?php

namespace Abedin\MultiPay\Tests;

use Abedin\MultiPay\Exceptions\InvalidPaymentPayloadException;
use Abedin\MultiPay\Facades\MultiPay;
use Abedin\MultiPay\Models\PaymentSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ZimbabweGatewaysTest extends TestCase
{
    private const ECOCASH = ['api_key' => 'eco-key', 'mode' => 'sandbox'];

    private const INNBUCKS = [
        'base_url' => 'https://staging.innbucks.co.zw',
        'api_key' => 'inn-key',
        'username' => 'merchant',
        'password' => 'secret',
    ];

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerHostAppRoutes();
    }

    public function test_ecocash_sends_prompt_and_returns_waiting_page(): void
    {
        Http::fake([
            '*/payment/instant/c2b/sandbox' => Http::response(['status' => 'PENDING'], 200),
        ]);

        $result = MultiPay::gateway('ecocash', self::ECOCASH)->pay([
            'amount' => 12.5,
            'currency' => 'USD',
            'order_id' => 'ORD-1',
            'customer' => ['phone' => '077 123 4567'],
        ]);

        $session = PaymentSession::first();

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('/multipay/mobile-money/' . $session->session_id . '/checkout', $result['payment_url']);

        Http::assertSent(fn ($request) => $request->hasHeader('X-API-KEY', 'eco-key')
            && $request['customerMsisdn'] === '263771234567'
            && $request['sourceReference'] === $session->session_id
            && $request['currency'] === 'USD');
    }

    public function test_ecocash_requires_a_zimbabwe_number(): void
    {
        $this->expectException(InvalidPaymentPayloadException::class);

        MultiPay::gateway('ecocash', self::ECOCASH)->pay([
            'amount' => 5,
            'currency' => 'USD',
            'order_id' => 'ORD-2',
        ]);
    }

    public function test_ecocash_confirm_marks_paid_on_successful_lookup(): void
    {
        Http::fake([
            '*/payment/instant/c2b/sandbox' => Http::response([], 200),
            '*/transaction/c2b/status/sandbox' => Http::response([
                'status' => 'SUCCESS',
                'ecocashReference' => 'MP123',
                'amount' => ['amount' => 12.5, 'currency' => 'USD'],
            ], 200),
        ]);

        MultiPay::gateway('ecocash', self::ECOCASH)->pay([
            'amount' => 12.5,
            'currency' => 'USD',
            'order_id' => 'ORD-3',
            'customer' => ['phone' => '263771234567'],
        ]);

        $session = PaymentSession::first();
        $result = MultiPay::confirm($session->session_id, Request::create('/x'), self::ECOCASH);

        $this->assertTrue($result->success);
        $this->assertSame('paid', $session->fresh()->status);
        $this->assertSame('MP123', $session->fresh()->payment_id);
    }

    public function test_ecocash_pending_lookup_stays_pending(): void
    {
        Http::fake([
            '*/payment/instant/c2b/sandbox' => Http::response([], 200),
            '*/transaction/c2b/status/sandbox' => Http::response(['status' => 'PENDING_VALIDATION'], 200),
        ]);

        MultiPay::gateway('ecocash', self::ECOCASH)->pay([
            'amount' => 3,
            'currency' => 'USD',
            'order_id' => 'ORD-4',
            'customer' => ['phone' => '0771234567'],
        ]);

        $result = MultiPay::confirm(PaymentSession::first()->session_id, Request::create('/x'), self::ECOCASH);

        $this->assertFalse($result->success);
        $this->assertSame('pending', $result->status);
    }

    public function test_innbucks_generates_code_in_cents(): void
    {
        Http::fake([
            '*/auth/third-party' => Http::response(['accessToken' => 'tok'], 200),
            '*/api/code/generate' => Http::response([
                'responseCode' => '00',
                'code' => '123456789',
                'authNumber' => 'A1',
                'qrCode' => 'iVBORw0KGgo=',
                'amount' => 1050,
            ], 200),
        ]);

        $result = MultiPay::gateway('innbucks', self::INNBUCKS)->pay([
            'amount' => 10.5,
            'currency' => 'USD',
            'order_id' => 'ORD-5',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('123456789', $result['payment_id']);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/code/generate')
            && $request->hasHeader('Authorization', 'Bearer tok')
            && $request->hasHeader('X-Api-Key', 'inn-key')
            && $request['amount'] === 1050
            && $request['type'] === 'PAYMENT');
    }

    public function test_innbucks_rejects_non_usd(): void
    {
        $this->expectExceptionMessage('InnBucks only accepts USD');

        MultiPay::gateway('innbucks', self::INNBUCKS)->pay([
            'amount' => 10,
            'currency' => 'ZWG',
            'order_id' => 'ORD-6',
        ]);
    }

    public function test_innbucks_confirm_maps_code_status(): void
    {
        Http::fake([
            '*/auth/third-party' => Http::response(['accessToken' => 'tok'], 200),
            '*/api/code/generate' => Http::response(['responseCode' => 0, 'code' => '555', 'authNumber' => 'A2'], 200),
            '*/api/code/inquiry' => Http::sequence()
                ->push(['responseCode' => 0, 'status' => 'New'], 200)
                ->push(['responseCode' => 96, 'status' => 'Claimed', 'amount' => '200'], 400),
        ]);

        MultiPay::gateway('innbucks', self::INNBUCKS)->pay([
            'amount' => 2,
            'currency' => 'USD',
            'order_id' => 'ORD-7',
        ]);

        $id = PaymentSession::first()->session_id;

        $first = MultiPay::confirm($id, Request::create('/x'), self::INNBUCKS);
        $this->assertSame('pending', $first->status);

        $second = MultiPay::confirm($id, Request::create('/x'), self::INNBUCKS);
        $this->assertTrue($second->success);
        $this->assertSame('paid', PaymentSession::first()->status);
    }

    public function test_waiting_page_renders_innbucks_code(): void
    {
        Http::fake([
            '*/auth/third-party' => Http::response(['accessToken' => 'tok'], 200),
            '*/api/code/generate' => Http::response(['responseCode' => 0, 'code' => '987654', 'authNumber' => 'A3'], 200),
        ]);

        $result = MultiPay::gateway('innbucks', self::INNBUCKS)->pay([
            'amount' => 1,
            'currency' => 'USD',
            'order_id' => 'ORD-8',
        ]);

        $this->get($result['payment_url'])
            ->assertOk()
            ->assertSee('987654');
    }
}
