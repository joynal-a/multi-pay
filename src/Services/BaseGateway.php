<?php

namespace Abedin\MultiPay\Services;

use Abedin\MultiPay\Contracts\PaymentGatewayInterface;
use Abedin\MultiPay\Data\PaymentRequestData;
use Abedin\MultiPay\Data\PaymentResponseData;
use Abedin\MultiPay\Models\PaymentSession;
use Abedin\MultiPay\Support\AppRoutes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

abstract class BaseGateway implements PaymentGatewayInterface
{
    /**
     * Currencies whose minor unit is not 2 decimals (ISO 4217).
     */
    protected const ZERO_DECIMAL_CURRENCIES = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
        'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    protected const THREE_DECIMAL_CURRENCIES = [
        'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND',
    ];

    protected array $config = [];
    protected array $rawConfig = [];
    protected string $gatewayName;
    protected ?PaymentSession $paymentSession = null;

    /**
     *  Get required config keys for the gateway
     *
     * @return array
     */
    abstract public function needyConfig(): array;

    /**
     * Convert the normalized payment request into a gateway-specific request.
     */
    abstract protected function requestPayment(PaymentRequestData $payment): PaymentResponseData;

    /**
     * Confirm with the gateway (server-side) whether this session was really
     * paid. Never trust redirect/request data alone — query the gateway using
     * the reference stored at initiation, or validate a signed payload.
     */
    abstract protected function verifyPayment(PaymentSession $session, Request $request): PaymentResponseData;

    /**
     * get Ready for making payment link
     *
     * @param string $name
     * @param array $configOverride When given, used instead of the database lookup (useful for tests).
     * @return void
     */
    public function initialize(string $name, array $configOverride = []): void
    {
        $this->gatewayName = $name;

        $gatewayKey = config("multipay.gateways.$name");

        if ($gatewayKey === null) {
            throw new \Exception("Gateway '{$name}' has no entry in config/multipay.php under 'gateways'.");
        }

        if (($gatewayKey['is_active'] ?? true) === false) {
            throw new \Exception("Gateway '{$name}' is disabled (is_active = false in config/multipay.php).");
        }

        $needs = $this->needyConfig();

        $config = $configOverride !== [] || $needs === []
            ? $configOverride
            : $this->findConfig($name);
        $this->rawConfig = $config;

        foreach ($needs as $value) {
            $keyName = $configOverride !== [] ? $value : ($gatewayKey[$value] ?? $value);
            $this->config[$value] = $config[$keyName] ?? null;
        }

        foreach ($this->config as $key => $value) {
            if ($value === null || $value === '') {
                throw new \Exception("Gateway '{$name}' is missing required config value: {$key}");
            }
        }
    }

    public function pay(array $payload): array
    {
        $payment = PaymentRequestData::fromArray($payload);

        $this->paymentSession = $this->createPaymentSession($payment);

        $response = $this->requestPayment($payment);

        $this->paymentSession->update([
            'payment_id' => $response->paymentId,
            'payment_url' => $response->paymentUrl,
            'status' => $response->status,
            'raw_response' => $response->raw,
        ]);

        return $response->toArray();
    }

    /**
     * Entry point used by MultiPay::confirm(). Binds the session so URL
     * helpers keep working inside verifyPayment().
     */
    public function verify(PaymentSession $session, Request $request): PaymentResponseData
    {
        $this->paymentSession = $session;

        return $this->verifyPayment($session, $request);
    }

    /**
     * Find gateway configuration from database
     *
     * @param [required] $name
     * @return array
     */
    private function findConfig($name): array
    {
        $config = config("multipay");
        $table = DB::table($config['data_table'])
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->first();
        if (!$table) {
            throw new \Exception("Gateway '$name' has no credentials row in the '{$config['data_table']}' table.");
        }

        $json = json_decode((string) $table->{$config['json_column']}, true);

        if (!is_array($json)) {
            throw new \Exception("Gateway '$name' has invalid JSON in the '{$config['json_column']}' column.");
        }

        // Runtime switch managed by the admin UI (missing key = active, so
        // pre-existing rows keep working).
        if (($json['is_active'] ?? true) === false) {
            throw new \Exception("Gateway '$name' is deactivated.");
        }

        return $json;
    }

    protected function createPaymentSession(PaymentRequestData $payment): PaymentSession
    {
        // Set explicitly rather than relying on the model's creating hook —
        // Event::fake() in host-app tests would otherwise swallow it.
        return PaymentSession::create([
            'session_id' => (string) \Illuminate\Support\Str::uuid(),
            'gateway' => $this->gatewayName,
            'order_id' => $payment->orderId,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'customer' => $payment->customer->toArray(),
            'meta' => $payment->meta,
            'payload' => $payment->toArray(),
            'status' => 'pending',
        ]);
    }

    protected function successUrl(): string
    {
        return AppRoutes::url('success', $this->sessionIdentifier());
    }

    protected function cancelUrl(): string
    {
        return AppRoutes::url('cancel', $this->sessionIdentifier());
    }

    protected function callbackUrl(): string
    {
        return AppRoutes::url('callback', $this->sessionIdentifier());
    }

    protected function pendingUrl(): string
    {
        return AppRoutes::url('pending', $this->sessionIdentifier());
    }

    protected function failureUrl(): string
    {
        return AppRoutes::url('failure', $this->sessionIdentifier());
    }

    protected function errorUrl(): string
    {
        return AppRoutes::url('error', $this->sessionIdentifier());
    }

    protected function expiryUrl(): string
    {
        return AppRoutes::url('expiry', $this->sessionIdentifier());
    }

    protected function sessionIdentifier(): string
    {
        if (!$this->paymentSession) {
            throw new \RuntimeException('Payment session has not been created.');
        }

        return $this->paymentSession->routeIdentifier();
    }

    protected function currencyExponent(string $currency): int
    {
        $currency = strtoupper($currency);

        if (in_array($currency, self::ZERO_DECIMAL_CURRENCIES, true)) {
            return 0;
        }

        if (in_array($currency, self::THREE_DECIMAL_CURRENCIES, true)) {
            return 3;
        }

        return 2;
    }

    protected function amountInMinorUnits(float $amount, int $exponent = 2): int
    {
        return (int) round($amount * (10 ** $exponent));
    }

    /**
     * Minor units with the correct exponent for the currency (JPY has 0
     * decimals, KWD has 3, most have 2).
     */
    protected function minorAmount(float $amount, string $currency): int
    {
        return $this->amountInMinorUnits($amount, $this->currencyExponent($currency));
    }

    /**
     * For gateways that need a signed browser POST: store the prepared form
     * on the session and point the payment URL at the package's generic
     * auto-submit page.
     */
    protected function formPostResponse(
        PaymentRequestData $payment,
        string $action,
        array $fields,
        string $label,
        ?string $paymentId = null,
        array $extraRaw = [],
        string $method = 'POST'
    ): PaymentResponseData {
        return new PaymentResponseData(
            true,
            $this->gatewayName,
            $paymentId ?? $payment->orderId,
            AppRoutes::internalUrl('form.checkout', $this->sessionIdentifier()),
            'pending',
            true,
            $label . ' checkout page generated successfully.',
            array_merge($extraRaw, [
                'multipay_form' => [
                    'label' => $label,
                    'action' => $action,
                    'method' => $method,
                    'fields' => $fields,
                ],
            ])
        );
    }

    /**
     * Convenience builders for verifyPayment() implementations.
     */
    protected function verified(PaymentSession $session, ?string $paymentId, array $raw = [], string $message = 'Payment verified successfully.'): PaymentResponseData
    {
        return new PaymentResponseData(
            true,
            $this->gatewayName,
            $paymentId ?? $session->payment_id,
            $session->payment_url,
            'paid',
            false,
            $message,
            $raw
        );
    }

    protected function unverified(PaymentSession $session, string $message, array $raw = [], string $status = 'failed'): PaymentResponseData
    {
        return new PaymentResponseData(
            false,
            $this->gatewayName,
            $session->payment_id,
            $session->payment_url,
            $status,
            false,
            $message,
            $raw
        );
    }
}
