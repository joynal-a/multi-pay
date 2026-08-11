<?php

namespace Abedin\MultiPay\Support;

use Abedin\MultiPay\Exceptions\MissingRouteException;
use Illuminate\Support\Facades\Route;

/**
 * Builds URLs for the routes the HOST APP owns (success, cancel, failure,
 * callback, ...). The package never defines these routes — the app registers
 * them under the names configured in multipay.routes, and this resolver only
 * turns those names into URLs to hand to the gateways.
 */
class AppRoutes
{
    /**
     * Optional route types fall back to a required one when the app has not
     * registered them: a "pending" return is still a success page, and
     * error/expiry are failures.
     */
    protected const FALLBACKS = [
        'pending' => 'success',
        'error' => 'failure',
        'expiry' => 'failure',
    ];

    public static function url(string $type, string $sessionIdentifier): string
    {
        $name = static::routeName($type);

        if (!Route::has($name) && isset(static::FALLBACKS[$type])) {
            $name = static::routeName(static::FALLBACKS[$type]);
        }

        if (!Route::has($name)) {
            throw new MissingRouteException(
                "MultiPay needs a route named '{$name}' for its '{$type}' URL, but your app has not registered it. " .
                "Define it (Route::match(['get','post'], ...)->name('{$name}')) and call MultiPay::confirm(\$session, \$request) inside it."
            );
        }

        return route($name, ['session' => $sessionIdentifier]);
    }

    public static function routeName(string $type): string
    {
        return (string) config("multipay.routes.$type", 'multipay.' . $type);
    }

    public static function internalUrl(string $name, string $sessionIdentifier): string
    {
        $as = (string) config('multipay.internal_routes.as', 'multipay.internal.');

        return route($as . $name, ['session' => $sessionIdentifier]);
    }
}
