<?php

namespace Abedin\MultiPay\Contracts;

use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Models\PaymentSession;
use Illuminate\Http\Request;

interface PaymentGatewayInterface
{
    public function initialize(string $name, array $configOverride = []): void;

    public function pay(array $payload): array;

    public function verify(PaymentSession $session, Request $request): PaymentResponseData;
}
