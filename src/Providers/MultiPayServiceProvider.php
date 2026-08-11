<?php

namespace Abedin\MultiPay\Providers;

use Illuminate\Support\ServiceProvider;
use Abedin\MultiPay\Managers\PaymentManager;

class MultiPayServiceProvider extends ServiceProvider
{
    /**
     * Ragister package config path name here.
     * @param string
     */
    private const CONFIG_FILE = __DIR__ . '/../../Config/multipay.php';
    private const MIGRATIONS = __DIR__ . '/../../migrations/';

    /**
     * Ragister package path name here.
     * @param string
     */
    private const PATH_VIEWS = __DIR__ . '/../../resources/views';

    public function register()
    {
        $this->app->singleton('multipay', function () {
            return new PaymentManager();
        });

        $this->mergeConfigFrom(self::CONFIG_FILE, 'multipay');
    }

    public function boot()
    {
        $this->loadRoutesFrom(__DIR__ . '/../../routes/web.php');
        $this->loadMigrationsFrom(self::MIGRATIONS);

        if (is_dir(self::PATH_VIEWS)) {
            $this->loadViewsFrom(self::PATH_VIEWS, 'joynala.multi-pay');
        }

        $this->publishes([
            self::CONFIG_FILE => config_path('multipay.php'),
        ], 'multi-pay-config');

        $this->publishes([
            self::MIGRATIONS => database_path('migrations/'),
        ], 'multi-pay-migrations');

        $this->publishes([
            __DIR__ . '/../../resources/icons' => public_path('vendor/multipay/icons'),
        ], 'multi-pay-icons');

        $this->publishes([
            self::CONFIG_FILE => config_path('multipay.php'),
            self::MIGRATIONS => database_path('migrations/'),
        ], 'multi-pay');
    }
}
