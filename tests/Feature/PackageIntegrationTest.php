<?php

declare(strict_types=1);

namespace Shaykhnazar\HikvisionIsapi\Tests\Feature;

use Orchestra\Testbench\TestCase;
use Shaykhnazar\HikvisionIsapi\Client\DeviceManager;
use Shaykhnazar\HikvisionIsapi\Client\HikvisionClient;
use Shaykhnazar\HikvisionIsapi\Client\Providers\CallbackDeviceProvider;
use Shaykhnazar\HikvisionIsapi\Facades\Hikvision;
use Shaykhnazar\HikvisionIsapi\Facades\HikvisionIsapi;
use Shaykhnazar\HikvisionIsapi\HikvisionIsapiServiceProvider;

final class PackageIntegrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [HikvisionIsapiServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('hikvision.devices.primary.password', 'review-fixture');
    }

    public function test_provider_bindings_and_facades_resolve_without_hardware(): void
    {
        $this->assertSame(['primary'], Hikvision::availableDevices());
        $this->assertSame($this->app->make(HikvisionClient::class), HikvisionIsapi::getFacadeRoot());
        foreach (['DeviceService', 'PersonService', 'CardService', 'FaceService', 'FingerprintService', 'AccessControlService', 'EventService', 'EventNotificationService'] as $service) {
            $class = 'Shaykhnazar\\HikvisionIsapi\\Services\\'.$service;
            $this->assertInstanceOf($class, $this->app->make($class));
        }
    }

    public function test_custom_device_provider_is_used(): void
    {
        $this->app->instance('hikvision.device.provider', CallbackDeviceProvider::fromArray([
            'review' => ['ip' => '127.0.0.1', 'port' => 80, 'username' => 'review', 'password' => 'review-fixture', 'protocol' => 'http', 'timeout' => 1, 'verify_ssl' => false],
        ], 'review'));
        $manager = $this->app->make(DeviceManager::class);
        $this->assertSame(['review'], $manager->availableDevices());
        $this->assertInstanceOf(HikvisionClient::class, $manager->default());
    }

    public function test_configuration_can_be_published(): void
    {
        $this->artisan('vendor:publish', ['--provider' => HikvisionIsapiServiceProvider::class, '--tag' => 'hikvision-config', '--force' => true])->assertExitCode(0);
        $this->assertFileExists(config_path('hikvision.php'));
    }

    public function test_configuration_can_be_cached(): void
    {
        try {
            $this->artisan('config:cache')->assertExitCode(0);
            $this->assertFileExists($this->app->getCachedConfigPath());
        } finally {
            $this->artisan('config:clear')->assertExitCode(0);
        }
    }
}
