<?php

namespace Abedin\MultiPay\Tests;

use Abedin\MultiPay\Exceptions\MissingRouteException;
use Abedin\MultiPay\Facades\MultiPay;
use Abedin\MultiPay\Models\PaymentSession;
use InvalidArgumentException;

class PaymentManagerTest extends TestCase
{
    public function test_unknown_gateway_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MultiPay::gateway('does-not-exist');
    }

    public function test_inactive_gateway_throws(): void
    {
        config(['multipay.gateways.demo.is_active' => false]);

        $this->expectExceptionMessage("Gateway 'demo' is disabled");

        MultiPay::gateway('demo');
    }

    public function test_demo_pay_creates_uuid_session_and_returns_payment_url(): void
    {
        $this->registerHostAppRoutes();

        $result = MultiPay::gateway('demo')->pay([
            'amount' => 100,
            'currency' => 'USD',
            'order_id' => 'ORD-99',
        ]);

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['payment_url']);

        $session = PaymentSession::first();
        $this->assertNotNull($session->session_id);
        // UUID in the URL, never the auto-increment id
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $session->session_id);
        $this->assertStringContainsString($session->session_id, $result['payment_url']);
        $this->assertStringNotContainsString('/payment/' . $session->id . '/', $result['payment_url']);
    }

    public function test_pay_fails_clearly_when_host_routes_missing(): void
    {
        $this->expectException(MissingRouteException::class);
        $this->expectExceptionMessageMatches('/multipay\.success/');

        MultiPay::gateway('demo')->pay([
            'amount' => 100,
            'currency' => 'USD',
            'order_id' => 'ORD-100',
        ]);
    }
}
