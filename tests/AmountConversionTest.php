<?php

namespace Abedin\MultiPay\Tests;

use Abedin\MultiPay\Services\DemoGateway;
use PHPUnit\Framework\TestCase;

class AmountConversionTest extends TestCase
{
    private function convert(float $amount, string $currency): int
    {
        $gateway = new DemoGateway();
        $method = new \ReflectionMethod($gateway, 'minorAmount');

        return $method->invoke($gateway, $amount, $currency);
    }

    public function test_two_decimal_currency(): void
    {
        $this->assertSame(1999, $this->convert(19.99, 'USD'));
    }

    public function test_float_precision_does_not_truncate(): void
    {
        // (int)(19.99 * 100) === 1998 — the old bug this guards against.
        $this->assertSame(1999, $this->convert(19.99, 'EUR'));
        $this->assertSame(4110, $this->convert(41.10, 'USD'));
    }

    public function test_zero_decimal_currency(): void
    {
        $this->assertSame(500, $this->convert(500.0, 'JPY'));
    }

    public function test_three_decimal_currency(): void
    {
        $this->assertSame(12345, $this->convert(12.345, 'KWD'));
    }
}
