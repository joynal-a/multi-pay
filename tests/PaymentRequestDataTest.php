<?php

namespace Abedin\MultiPay\Tests;

use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Exceptions\InvalidPaymentPayloadException;
use PHPUnit\Framework\TestCase;

class PaymentRequestDataTest extends TestCase
{
    public function test_builds_from_valid_payload(): void
    {
        $data = PaymentRequestData::fromArray([
            'amount' => 19.99,
            'currency' => 'usd',
            'order_id' => 'ORD-1',
            'customer' => ['name' => 'Joynal', 'email' => 'joynal@example.com'],
            'meta' => ['title' => 'Test'],
        ]);

        $this->assertSame(19.99, $data->amount);
        $this->assertSame('USD', $data->currency);
        $this->assertSame('ORD-1', $data->orderId);
        $this->assertSame('Joynal', $data->customer->name);
        $this->assertSame(['title' => 'Test'], $data->meta);
    }

    public function test_missing_amount_throws(): void
    {
        $this->expectException(InvalidPaymentPayloadException::class);

        PaymentRequestData::fromArray(['currency' => 'USD', 'order_id' => 'ORD-1']);
    }

    public function test_missing_currency_throws(): void
    {
        $this->expectException(InvalidPaymentPayloadException::class);

        PaymentRequestData::fromArray(['amount' => 10, 'order_id' => 'ORD-1']);
    }

    public function test_missing_order_id_throws(): void
    {
        $this->expectException(InvalidPaymentPayloadException::class);

        PaymentRequestData::fromArray(['amount' => 10, 'currency' => 'USD']);
    }
}
