<?php

namespace Abedin\MultiPay\Tests;

use Abedin\MultiPay\Facades\MultiPay;
use Abedin\MultiPay\Support\GatewayConfigStore;
use Illuminate\Support\Facades\DB;

class AdminUiTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        // No auth scaffolding in the test app — exercise endpoints bare.
        $app['config']->set('multipay.admin_middleware', ['web']);
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    }

    public function test_store_lists_every_registered_gateway(): void
    {
        $all = GatewayConfigStore::all();
        $names = array_column($all, 'name');

        $this->assertContains('stripe', $names);
        $this->assertContains('demo', $names);
        $this->assertCount(count($names), array_unique($names));
    }

    public function test_update_persists_only_mapped_fields(): void
    {
        GatewayConfigStore::update('stripe', [
            'secret_key_data' => 'sk_test_123',
            'public_key_data' => 'pk_test_123',
            'hack_field' => 'nope',
        ]);

        $row = DB::table('gateways')->where('name', 'stripe')->first();
        $json = json_decode($row->data, true);

        $this->assertSame('sk_test_123', $json['secret_key_data']);
        $this->assertArrayNotHasKey('hack_field', $json);
    }

    public function test_toggle_endpoint_flips_is_active_and_gateway_respects_it(): void
    {
        GatewayConfigStore::update('stripe', [
            'secret_key_data' => 'sk_test_123',
            'public_key_data' => 'pk_test_123',
        ]);

        $this->postJson(route('multipay.internal.admin.gateways.toggle', 'stripe'), ['active' => true])
            ->assertOk()
            ->assertJson(['ok' => true, 'active' => true]);

        $this->postJson(route('multipay.internal.admin.gateways.toggle', 'stripe'), ['active' => false])
            ->assertOk();

        // A deactivated gateway must refuse to build from the DB path.
        $this->expectExceptionMessage("Gateway 'stripe' is deactivated.");
        MultiPay::gateway('stripe');
    }

    public function test_activation_blocked_while_credentials_missing(): void
    {
        $this->postJson(route('multipay.internal.admin.gateways.toggle', 'stripe'), ['active' => true])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);
    }

    public function test_update_endpoint_persists_fields(): void
    {
        $this->postJson(route('multipay.internal.admin.gateways.update', 'paystack'), [
            'fields' => ['secret_key_data' => 'sk_live_x'],
        ])->assertOk()->assertJson(['ok' => true]);

        $json = json_decode(DB::table('gateways')->where('name', 'paystack')->value('data'), true);
        $this->assertSame('sk_live_x', $json['secret_key_data']);
    }

    public function test_logo_url_is_editable_and_wins_over_packaged_icon(): void
    {
        $this->postJson(route('multipay.internal.admin.gateways.update', 'stripe'), [
            'fields' => ['icon' => 'https://cdn.example.org/stripe.png'],
        ])->assertOk();

        $entry = collect(GatewayConfigStore::all())->firstWhere('name', 'stripe');

        $this->assertSame('https://cdn.example.org/stripe.png', $entry['icon']);
        $this->assertSame('https://cdn.example.org/stripe.png', $entry['icon_url_value']);
    }

    public function test_active_gateways_returns_only_active_with_logo(): void
    {
        GatewayConfigStore::update('paystack', ['secret_key_data' => 'sk_x']);
        GatewayConfigStore::setActive('paystack', true);
        GatewayConfigStore::setActive('demo', true);
        // stripe row exists but inactive
        GatewayConfigStore::update('stripe', ['secret_key_data' => 'sk_y', 'public_key_data' => 'pk_y']);
        GatewayConfigStore::setActive('stripe', false);

        $active = GatewayConfigStore::activeGateways();
        $names = array_column($active, 'name');

        $this->assertContains('paystack', $names);
        $this->assertContains('demo', $names);
        $this->assertNotContains('stripe', $names);

        $paystack = collect($active)->firstWhere('name', 'paystack');
        $this->assertSame('Paystack', $paystack['label']);
        $this->assertNotNull($paystack['icon_svg']);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $paystack['icon_data_uri']);
    }

    public function test_admin_blade_renders_via_include(): void
    {
        $this->registerHostAppRoutes();

        $html = view()->make('joynala.multi-pay::admin.gateways')->render();

        $this->assertStringContainsString('Payment Gateways', $html);
        $this->assertStringContainsString('data-mp-gateway="stripe"', $html);
        $this->assertStringContainsString('data-mp-gateway="demo"', $html);
    }

    public function test_db_backed_gateway_flow_still_works_via_db_credentials(): void
    {
        $this->registerHostAppRoutes();

        // demo needs no config; but verify a DB-credentialed gateway resolves
        GatewayConfigStore::update('paystack', ['secret_key_data' => 'sk_test_abc']);
        GatewayConfigStore::setActive('paystack', true);

        $gateway = MultiPay::gateway('paystack');
        $this->assertInstanceOf(\Abedin\MultiPay\Services\PaystackGateway::class, $gateway);
    }
}
