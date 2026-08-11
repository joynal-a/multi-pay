<?php

namespace Abedin\MultiPay\Data;

class PaymentCustomerData
{
    public ?string $name;
    public ?string $email;

    public function __construct(?string $name = null, ?string $email = null)
    {
        $this->name = $name;
        $this->email = $email;
    }

    public static function fromArray(array $customer): self
    {
        return new self(
            $customer['name'] ?? null,
            $customer['email'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
        ];
    }
}
