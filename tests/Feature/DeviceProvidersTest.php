<?php

declare(strict_types=1);

namespace Shaykhnazar\HikvisionIsapi\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Shaykhnazar\HikvisionIsapi\Client\Providers\CallbackDeviceProvider;
use Shaykhnazar\HikvisionIsapi\Client\Providers\DatabaseDeviceProvider;
use Shaykhnazar\HikvisionIsapi\Tests\TestCase;

class DeviceProvidersTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        $app['config']->set('database.default', 'testing');
        foreach (['testing', 'devices'] as $connection) {
            $app['config']->set("database.connections.{$connection}", ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        }
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['testing', 'devices'] as $connection) {
            Schema::connection($connection)->create('terminals', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('ip_address');
                $table->string('username')->default('admin');
                $table->text('password');
                $table->integer('port')->default(80);
                $table->integer('timeout')->default(30);
                $table->string('protocol')->default('http');
                $table->string('verify_ssl')->default('false');
                $table->boolean('active')->default(true);
                $table->integer('tenant_id')->default(1);
                $table->softDeletes();
            });
        }
    }

    private function terminal(string $name, array $attributes = []): ProviderTerminal
    {
        return ProviderTerminal::create(array_merge(['name' => $name, 'ip_address' => '192.0.2.1', 'password' => 'secret'], $attributes));
    }

    public function test_model_provider_preserves_connection_scopes_filters_and_casts(): void
    {
        $this->terminal('active');
        $this->terminal('inactive', ['active' => false]);
        $this->terminal('deleted')->delete();
        DB::table('terminals')->insert(['name' => 'wrong-connection', 'ip_address' => '192.0.2.2', 'password' => 'decoy']);

        $provider = DatabaseDeviceProvider::fromModel(ProviderTerminal::class, configColumns: ['ip' => 'ip_address'], query: fn ($query) => $query->where('active', true));

        $this->assertSame(['active'], $provider->getDeviceNames());
        $this->assertSame('secret', $provider->getDeviceConfig('active')['password']);
        $this->assertSame(80, $provider->getDeviceConfig('active')['port']);
        $this->assertFalse($provider->getDeviceConfig('active')['verify_ssl']);
        $this->assertNull($provider->getDeviceConfig('inactive'));
    }

    public function test_default_model_mapping_is_not_empty(): void
    {
        $this->terminal('primary');
        $provider = DatabaseDeviceProvider::fromModel(ProviderTerminal::class);

        $this->assertSame('192.0.2.1', $provider->getDeviceConfig('primary')['ip']);
        $this->assertSame('secret', $provider->getDeviceConfig('primary')['password']);
    }

    public function test_model_callback_is_reapplied_after_tenant_cache_clear(): void
    {
        $this->terminal('tenant-one', ['tenant_id' => 1]);
        $this->terminal('tenant-two', ['tenant_id' => 2]);
        $tenant = 1;
        $provider = DatabaseDeviceProvider::fromModel(ProviderTerminal::class, query: function ($query) use (&$tenant): void {
            $query->where('tenant_id', $tenant);
        });

        $this->assertSame(['tenant-one'], $provider->getDeviceNames());
        $tenant = 2;
        $provider->clearCache();
        $this->assertSame(['tenant-two'], $provider->getDeviceNames());
        $this->assertNull($provider->getDeviceConfig('tenant-one'));
    }

    public function test_cached_table_records_are_normalized_and_can_be_invalidated(): void
    {
        DB::table('terminals')->insert(['name' => 'primary', 'ip_address' => '192.0.2.1', 'password' => 'old']);
        $provider = CallbackDeviceProvider::fromCache('test-devices');

        $this->assertSame('old', $provider->getDeviceConfig('primary')['password']);
        $this->assertSame(80, $provider->getDeviceConfig('primary')['port']);
        $this->assertFalse($provider->getDeviceConfig('primary')['verify_ssl']);
        DB::table('terminals')->where('name', 'primary')->update(['password' => 'new']);
        $provider->clearCache();
        $this->assertSame('new', $provider->getDeviceConfig('primary')['password']);
        $this->assertNull($provider->getDeviceConfig('missing'));
    }

    public function test_eloquent_callback_provider_can_refresh_credentials(): void
    {
        $terminal = $this->terminal('primary');
        $provider = CallbackDeviceProvider::fromEloquent(ProviderTerminal::query());
        $this->assertSame('secret', $provider->getDeviceConfig('primary')['password']);
        $terminal->update(['password' => 'rotated']);
        $provider->clearCache();
        $this->assertSame('rotated', $provider->getDeviceConfig('primary')['password']);
        $this->assertFalse($provider->getDeviceConfig('primary')['verify_ssl']);
    }
}

/** @property string $password */
class ProviderTerminal extends Model
{
    use SoftDeletes;

    protected $connection = 'devices';

    protected $table = 'terminals';

    protected $guarded = [];

    protected $casts = ['password' => 'encrypted', 'active' => 'boolean'];

    public $timestamps = false;
}
