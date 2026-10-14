<?php

declare(strict_types=1);

namespace Shaykhnazar\HikvisionIsapi\Tests\Feature;

use Shaykhnazar\HikvisionIsapi\Client\Contracts\HttpClientInterface;
use Shaykhnazar\HikvisionIsapi\Client\DeviceManager;
use Shaykhnazar\HikvisionIsapi\Client\Providers\CallbackDeviceProvider;
use Shaykhnazar\HikvisionIsapi\Exceptions\HikvisionException;
use Shaykhnazar\HikvisionIsapi\Facades\Hikvision;
use Shaykhnazar\HikvisionIsapi\Facades\HikvisionIsapi;
use Shaykhnazar\HikvisionIsapi\Services\DeviceService;
use Shaykhnazar\HikvisionIsapi\Tests\Support\RecordingHttpClient;
use Shaykhnazar\HikvisionIsapi\Tests\TestCase;

class DeviceLifecycleTest extends TestCase
{
    /** @phpstan-impure Reads a recording that changes after each HTTP request. */
    private function lastPassword(RecordingHttpClient $http): string
    {
        return $http->options['auth'][1];
    }

    private function provider(string $password): CallbackDeviceProvider
    {
        return CallbackDeviceProvider::fromArray(['primary' => [
            'ip' => '192.0.2.1', 'port' => 80, 'protocol' => 'http', 'username' => 'admin', 'password' => $password, 'timeout' => 1, 'verify_ssl' => false,
        ]]);
    }

    public function test_new_service_resolutions_and_facade_follow_provider_changes(): void
    {
        $http = new RecordingHttpClient;
        $this->app->instance(HttpClientInterface::class, $http);
        $manager = $this->app->make(DeviceManager::class);
        $manager->setProvider($this->provider('old'));
        $this->app->make(DeviceService::class)->getInfo();
        HikvisionIsapi::get('/ISAPI/System/deviceInfo');
        $this->assertSame('old', $this->lastPassword($http));

        $manager->setProvider($this->provider('new'));
        $this->app->make(DeviceService::class)->getInfo();
        $this->assertSame('new', $this->lastPassword($http));
        HikvisionIsapi::get('/ISAPI/System/deviceInfo');
        $this->assertSame('new', $this->lastPassword($http));
    }

    public function test_manager_is_reset_at_a_laravel_scope_boundary(): void
    {
        $manager = $this->app->make(DeviceManager::class);
        $this->assertSame($manager, Hikvision::getFacadeRoot());
        $this->app->forgetScopedInstances();

        $this->assertNotSame($manager, $this->app->make(DeviceManager::class));
        $this->assertSame($this->app->make(DeviceManager::class), Hikvision::getFacadeRoot());
    }

    public function test_cached_client_is_replaced_when_provider_credentials_change(): void
    {
        $password = 'old';
        $provider = new CallbackDeviceProvider(fn () => ['primary'], function () use (&$password): array {
            return ['ip' => '192.0.2.1', 'port' => 80, 'protocol' => 'http', 'username' => 'admin', 'password' => $password, 'timeout' => 1, 'verify_ssl' => false];
        }, 'primary');
        $manager = $this->app->make(DeviceManager::class);
        $manager->setProvider($provider);
        $before = $manager->default();
        $password = 'new';

        $this->assertNotSame($before, $manager->default());
    }

    public function test_cached_client_cannot_bypass_a_provider_removal(): void
    {
        $names = ['primary'];
        $config = $this->provider('secret')->getDeviceConfig('primary');
        $provider = new CallbackDeviceProvider(function () use (&$names): array {
            return $names;
        }, fn () => $config, 'primary');
        $manager = $this->app->make(DeviceManager::class);
        $manager->setProvider($provider);
        $manager->default();
        $names = [];

        $this->expectException(HikvisionException::class);
        $manager->default();
    }
}
