<?php

namespace Abedin\MultiPay\Support;

use Abedin\MultiPay\Enums\Gateways;
use Abedin\MultiPay\Managers\PaymentManager;
use Abedin\MultiPay\Services\BaseGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads and writes gateway credential rows in the host app's data table
 * (config: multipay.data_table / multipay.json_column). Powers the includable
 * admin UI. The runtime on/off switch lives INSIDE the JSON under the
 * reserved key "is_active" (missing = active, so existing rows keep working).
 */
class GatewayConfigStore
{
    /**
     * JSON keys managed by the package itself, not gateway credentials.
     */
    protected const RESERVED_KEYS = ['is_active', 'icon'];

    /**
     * Everything the admin UI needs, for every registered gateway.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $registry = static::registry();

        // Host tables may store names with any casing ("Stripe" vs "stripe") —
        // normalize so lookups never miss.
        $rows = [];
        foreach (DB::table(static::table())->pluck(static::jsonColumn(), 'name') as $rowName => $rowJson) {
            $rows[strtolower((string) $rowName)] = $rowJson;
        }

        $result = [];

        foreach ($registry as $name => $class) {
            $section = (array) config("multipay.gateways.$name", []);
            $hasRow = array_key_exists($name, $rows);
            $json = static::decode($rows[$name] ?? null);
            $requiredKeys = static::requiredJsonKeys($name, $class, $section);
            $fields = static::fields($section, $json);

            $missing = array_filter($requiredKeys, static fn ($key) => trim((string) ($json[$key] ?? '')) === '');

            $dbIcon = trim((string) ($json['icon'] ?? ''));

            $result[] = [
                'name' => $name,
                'label' => static::label($name),
                // admin-set logo URL (DB) wins over the config URL
                'icon' => $dbIcon !== '' ? $dbIcon : static::customIconUrl($section),
                'icon_svg' => static::iconSvg($name),
                'icon_url_value' => $dbIcon, // raw DB value for the edit modal
                'enabled_in_code' => ($section['is_active'] ?? true) !== false,
                'configured' => $hasRow && $missing === [],
                'active' => $hasRow && (($json['is_active'] ?? true) !== false),
                'fields' => $fields,
                'missing' => array_values($missing),
            ];
        }

        return $result;
    }

    /**
     * Only the ACTIVE gateways, ready for a checkout page or a JSON API:
     * name, label, and logo (admin/config URL when set, otherwise the
     * packaged SVG as a ready-to-use data URI).
     *
     * @return array<int, array{name: string, label: string, icon: string|null, icon_svg: string|null, icon_data_uri: string|null}>
     */
    public static function activeGateways(): array
    {
        $active = [];

        foreach (static::all() as $gateway) {
            if (!$gateway['active'] || !$gateway['enabled_in_code']) {
                continue;
            }

            $active[] = [
                'name' => $gateway['name'],
                'label' => $gateway['label'],
                'icon' => $gateway['icon'],
                'icon_svg' => $gateway['icon_svg'],
                'icon_data_uri' => $gateway['icon_svg']
                    ? 'data:image/svg+xml;base64,' . base64_encode($gateway['icon_svg'])
                    : null,
            ];
        }

        return $active;
    }

    public static function update(string $name, array $fields): void
    {
        static::assertKnown($name);

        $allowed = array_column(static::fields((array) config("multipay.gateways.$name", []), []), 'json_key');
        $allowed[] = 'icon'; // logo URL is editable from the admin modal
        $json = static::rowJson($name);

        foreach ($fields as $key => $value) {
            if (in_array($key, $allowed, true)) {
                $json[$key] = is_string($value) ? trim($value) : $value;
            }
        }

        static::save($name, $json);
    }

    public static function setActive(string $name, bool $active): void
    {
        static::assertKnown($name);

        $json = static::rowJson($name);
        $json['is_active'] = $active;

        static::save($name, $json);
    }

    /**
     * The brand-colored SVG tile shipped with the package, or null.
     */
    public static function iconSvg(string $name): ?string
    {
        $path = dirname(__DIR__, 2) . '/resources/icons/' . basename($name) . '.svg';

        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    /**
     * A user-supplied icon URL from config — placeholder example.com URLs
     * don't count.
     */
    protected static function customIconUrl(array $section): ?string
    {
        $icon = $section['icon'] ?? null;

        if (!is_string($icon) || $icon === '' || str_contains($icon, 'example.com')) {
            return null;
        }

        return $icon;
    }

    public static function tableExists(): bool
    {
        return Schema::hasTable(static::table());
    }

    protected static function save(string $name, array $json): void
    {
        $existing = static::findRow($name);

        if ($existing) {
            DB::table(static::table())
                ->where('name', $existing->name)
                ->update([static::jsonColumn() => json_encode($json)]);

            return;
        }

        DB::table(static::table())->insert([
            'name' => $name,
            static::jsonColumn() => json_encode($json),
        ]);
    }

    protected static function rowJson(string $name): array
    {
        $row = static::findRow($name);

        return $row ? static::decode($row->{static::jsonColumn()}) : [];
    }

    protected static function findRow(string $name): ?object
    {
        return DB::table(static::table())
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->first();
    }

    /**
     * The editable fields for a gateway: every config-mapped credential key
     * (is_active / icon are UI concerns, not credentials).
     */
    protected static function fields(array $section, array $json): array
    {
        $fields = [];

        foreach ($section as $packageKey => $jsonKey) {
            if (in_array($packageKey, ['is_active', 'icon'], true) || !is_string($jsonKey)) {
                continue;
            }

            $fields[] = [
                'package_key' => $packageKey,
                'json_key' => $jsonKey,
                'value' => (string) ($json[$jsonKey] ?? ''),
            ];
        }

        return $fields;
    }

    /**
     * JSON keys that must be non-empty before the gateway can be activated
     * (the mapped needyConfig() keys).
     */
    protected static function requiredJsonKeys(string $name, string $class, array $section): array
    {
        if (!is_subclass_of($class, BaseGateway::class)) {
            return [];
        }

        $keys = [];

        foreach ((new $class())->needyConfig() as $packageKey) {
            $jsonKey = $section[$packageKey] ?? $packageKey;

            if (is_string($jsonKey)) {
                $keys[] = $jsonKey;
            }
        }

        return $keys;
    }

    protected static function label(string $name): string
    {
        $case = Gateways::tryFrom($name);

        return $case
            ? preg_replace('/(?<!^)([A-Z])/', ' $1', $case->name)
            : ucfirst($name);
    }

    protected static function registry(): array
    {
        $property = new \ReflectionProperty(PaymentManager::class, 'gateways');

        return $property->getDefaultValue();
    }

    protected static function assertKnown(string $name): void
    {
        if (!array_key_exists($name, static::registry())) {
            throw new \InvalidArgumentException("Gateway '{$name}' is not registered.");
        }
    }

    protected static function decode(mixed $raw): array
    {
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    protected static function table(): string
    {
        return (string) config('multipay.data_table', 'gateways');
    }

    protected static function jsonColumn(): string
    {
        return (string) config('multipay.json_column', 'data');
    }
}
