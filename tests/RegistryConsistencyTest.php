<?php

namespace Abedin\MultiPay\Tests;

use Abedin\MultiPay\Enums\Gateways;
use Abedin\MultiPay\Managers\PaymentManager;
use Abedin\MultiPay\Services\BaseGateway;

class RegistryConsistencyTest extends TestCase
{
    private function registry(): array
    {
        $manager = new PaymentManager();
        $property = new \ReflectionProperty($manager, 'gateways');

        return $property->getValue($manager);
    }

    public function test_every_registry_entry_has_class_config_and_enum(): void
    {
        $enumValues = array_map(fn (Gateways $case) => $case->value, Gateways::cases());

        foreach ($this->registry() as $name => $class) {
            $this->assertTrue(class_exists($class), "Gateway class missing for '{$name}'");
            $this->assertTrue(is_subclass_of($class, BaseGateway::class), "'{$name}' must extend BaseGateway");
            $this->assertNotNull(config("multipay.gateways.{$name}"), "config/multipay.php has no entry for '{$name}'");
            $this->assertContains($name, $enumValues, "Gateways enum has no case for '{$name}'");
        }
    }

    public function test_every_gateway_implements_verify(): void
    {
        foreach ($this->registry() as $name => $class) {
            $method = new \ReflectionMethod($class, 'verifyPayment');
            $this->assertFalse($method->isAbstract(), "'{$name}' does not implement verifyPayment()");
        }
    }

    public function test_every_enum_case_is_registered(): void
    {
        $registry = $this->registry();

        foreach (Gateways::cases() as $case) {
            $this->assertArrayHasKey($case->value, $registry, "Enum case '{$case->name}' is not in the registry");
        }
    }
}
