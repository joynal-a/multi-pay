<?php

namespace Abedin\MultiPay\Data;

class PaymentResponseData
{
    public bool $success;
    public string $gateway;
    public ?string $paymentId;
    public ?string $paymentUrl;
    public string $status;
    public bool $supportsIframe;
    public string $message;
    public array $raw;

    public function __construct(
        bool $success,
        string $gateway,
        ?string $paymentId,
        ?string $paymentUrl,
        string $status = 'pending',
        bool $supportsIframe = false,
        string $message = 'Payment link generated successfully.',
        array $raw = []
    ) {
        $this->success = $success;
        $this->gateway = $gateway;
        $this->paymentId = $paymentId;
        $this->paymentUrl = $paymentUrl;
        $this->status = $status;
        $this->supportsIframe = $supportsIframe;
        $this->message = $message;
        $this->raw = $raw;
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'gateway' => $this->gateway,
            'payment_id' => $this->paymentId,
            'payment_url' => $this->paymentUrl,
            'status' => $this->status,
            'supports_iframe' => $this->supportsIframe,
            'message' => $this->message,
            'raw' => $this->raw,
        ];
    }
}
