<?php

namespace Abedin\MultiPay\Tests;

use Abedin\MultiPay\Events\PaymentFailed;
use Abedin\MultiPay\Events\PaymentSucceeded;
use Abedin\MultiPay\Exceptions\PaymentSessionNotFoundException;
use Abedin\MultiPay\Facades\MultiPay;
use Abedin\MultiPay\Models\PaymentSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

class ConfirmFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->registerHostAppRoutes();
    }

    private function paySession(): PaymentSession
    {
        MultiPay::gateway('demo')->pay([
            'amount' => 250,
            'currency' => 'USD',
            'order_id' => 'ORD-7',
        ]);

        return PaymentSession::first();
    }

    public function test_confirm_verifies_and_marks_paid(): void
    {
        Event::fake();
        $session = $this->paySession();

        $result = MultiPay::confirm($session->session_id, Request::create('/x'));

        $this->assertTrue($result->success);
        $this->assertSame('paid', $result->status);
        $this->assertSame('paid', $session->fresh()->status);
        Event::assertDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    public function test_confirm_is_idempotent(): void
    {
        $session = $this->paySession();

        MultiPay::confirm($session->session_id, Request::create('/x'));
        $again = MultiPay::confirm($session->session_id, Request::create('/x'));

        $this->assertTrue($again->success);
        $this->assertSame('Payment already verified.', $again->message);
    }

    public function test_confirm_accepts_session_instance(): void
    {
        $session = $this->paySession();

        $result = MultiPay::confirm($session, Request::create('/x'));

        $this->assertTrue($result->success);
    }

    public function test_confirm_unknown_session_throws(): void
    {
        $this->expectException(PaymentSessionNotFoundException::class);

        MultiPay::confirm('non-existent-uuid', Request::create('/x'));
    }

    public function test_cancel_marks_pending_session_cancelled(): void
    {
        $session = $this->paySession();

        MultiPay::cancel($session->session_id);

        $this->assertSame('cancelled', $session->fresh()->status);
    }

    public function test_cancel_never_downgrades_a_paid_session(): void
    {
        $session = $this->paySession();
        MultiPay::confirm($session->session_id, Request::create('/x'));

        MultiPay::cancel($session->session_id);

        $this->assertSame('paid', $session->fresh()->status);
    }

    public function test_verification_exception_marks_failed_not_paid(): void
    {
        Event::fake();
        $session = $this->paySession();
        // Point the session at a gateway whose verify throws (scaffold).
        $session->update(['gateway' => 'onafriq']);
        config(['multipay.gateways.onafriq.is_active' => true]);

        $result = MultiPay::confirm($session->session_id, Request::create('/x'), [
            'api_key' => 'x', 'api_secret' => 'x', 'base_url' => 'https://example.com', 'channel' => 'card',
        ]);

        $this->assertFalse($result->success);
        $this->assertSame('failed', $session->fresh()->status);
        Event::assertDispatched(PaymentFailed::class);
    }
}
