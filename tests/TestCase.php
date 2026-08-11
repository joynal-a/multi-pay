<?php

namespace Abedin\MultiPay\Tests;

use Abedin\MultiPay\Providers\MultiPayServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app)
    {
        return [MultiPayServiceProvider::class];
    }

    protected function getPackageAliases($app)
    {
        return [
            'MultiPay' => \Abedin\MultiPay\Facades\MultiPay::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../migrations');
    }

    /**
     * Register the routes a host application is expected to own.
     */
    protected function registerHostAppRoutes(): void
    {
        Route::match(['get', 'post'], '/payment/{session}/success', fn ($session) => 'success')
            ->name('multipay.success');
        Route::match(['get', 'post'], '/payment/{session}/cancel', fn ($session) => 'cancel')
            ->name('multipay.cancel');
        Route::match(['get', 'post'], '/payment/{session}/failure', fn ($session) => 'failure')
            ->name('multipay.failure');
        Route::match(['get', 'post'], '/payment/{session}/callback', fn ($session) => 'callback')
            ->name('multipay.callback');

        Route::getRoutes()->refreshNameLookups();
    }
}
