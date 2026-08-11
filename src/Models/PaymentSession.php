<?php

namespace Abedin\MultiPay\Models;

use Abedin\MultiPay\Exceptions\PaymentSessionNotFoundException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PaymentSession extends Model
{
    protected $table = 'payment_sessions';

    protected $fillable = [
        'session_id',
        'gateway',
        'order_id',
        'payment_id',
        'payment_url',
        'amount',
        'currency',
        'customer',
        'meta',
        'status',
        'payload',
        'raw_response',
    ];

    protected $casts = [
        'customer' => 'array',
        'meta' => 'array',
        'payload' => 'array',
        'raw_response' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $session) {
            $session->session_id = $session->session_id ?: (string) Str::uuid();
        });
    }

    /**
     * The identifier exposed in URLs. A UUID, never the auto-increment id,
     * so payment sessions cannot be enumerated by guessing.
     */
    public function routeIdentifier(): string
    {
        return (string) $this->session_id;
    }

    public function getRouteKeyName(): string
    {
        return 'session_id';
    }

    public static function resolveByIdentifier(mixed $identifier): self
    {
        if ($identifier instanceof self) {
            return $identifier;
        }

        $session = static::query()->where('session_id', (string) $identifier)->first();

        if (!$session) {
            throw new PaymentSessionNotFoundException("Payment session '{$identifier}' not found.");
        }

        return $session;
    }
}
