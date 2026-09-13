<?php

namespace Abedin\MultiPay\Data;

class PaymentCustomerData
{
    public ?string $name;
    public ?string $email;
    public ?string $phone;

    public function __construct(?string $name = null, ?string $email = null, ?string $phone = null)
    {
        $this->name = $name;
        $this->email = $email;
        $this->phone = $phone;
    }

    public static function fromArray(array $customer): self
    {
        return new self(
            $customer['name'] ?? null,
            $customer['email'] ?? null,
            $customer['phone'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
        ];
    }
}
