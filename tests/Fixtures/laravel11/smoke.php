<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Shaykhnazar\HikvisionIsapi\Client\DeviceManager;
use Shaykhnazar\HikvisionIsapi\HikvisionIsapiServiceProvider;

require __DIR__.'/vendor/autoload.php';

foreach (['storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $directory) {
    $path = __DIR__.'/'.$directory;
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }
}

$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

if (!$app->bound(DeviceManager::class) || !class_exists('HikvisionIsapi')) {
    throw new RuntimeException('Composer package discovery did not register the provider and facade alias.');
}

try {
    foreach ([
        ['vendor:publish', ['--provider' => HikvisionIsapiServiceProvider::class, '--tag' => 'hikvision-config', '--force' => true]],
        ['config:cache', []],
    ] as [$command, $parameters]) {
        if ($kernel->call($command, $parameters) !== 0) {
            throw new RuntimeException($kernel->output());
        }
    }

    $cached = require $app->getCachedConfigPath();
    if (!isset($cached['hikvision']['devices']['primary'])) {
        throw new RuntimeException('Published Hikvision configuration was not cached.');
    }

    echo "Package discovery, facade alias, configuration publishing and configuration caching passed.\n";
} finally {
    $kernel->call('config:clear');
}
