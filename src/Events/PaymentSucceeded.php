<?php

namespace Abedin\MultiPay\Events;

use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Models\PaymentSession;

class PaymentSucceeded
{
    public function __construct(
        public PaymentSession $session,
        public PaymentResponseData $result
    ) {
    }
}
