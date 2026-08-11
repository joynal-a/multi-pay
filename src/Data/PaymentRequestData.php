<?php

namespace Abedin\MultiPay\Data;

use Abedin\MultiPay\Exceptions\InvalidPaymentPayloadException;

class PaymentRequestData
{
    public float $amount;
    public string $currency;
    public string $orderId;
    public PaymentCustomerData $customer;
    public array $meta;

    public function __construct(
        float $amount,
        string $currency,
        string $orderId,
        ?PaymentCustomerData $customer = null,
        array $meta = []
    ) {
        $this->amount = $amount;
        $this->currency = strtoupper($currency);
        $this->orderId = $orderId;
        $this->customer = $customer ?? new PaymentCustomerData();
        $this->meta = $meta;
    }

    public static function fromArray(array $payload): self
    {
        foreach (['amount', 'currency', 'order_id'] as $field) {
            if (!array_key_exists($field, $payload) || $payload[$field] === null || $payload[$field] === '') {
                throw new InvalidPaymentPayloadException("Missing required payment field: {$field}");
            }
        }

        return new self(
            (float) $payload['amount'],
            (string) $payload['currency'],
            (string) $payload['order_id'],
            PaymentCustomerData::fromArray($payload['customer'] ?? []),
            is_array($payload['meta'] ?? null) ? $payload['meta'] : []
        );
    }

    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'order_id' => $this->orderId,
            'customer' => $this->customer->toArray(),
            'meta' => $this->meta,
        ];
    }
}
