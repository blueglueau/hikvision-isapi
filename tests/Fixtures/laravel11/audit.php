<?php

declare(strict_types=1);

// The compatibility fixture accepts only its documented framework advisories.
// Installation exceptions must never hide a new package or advisory from CI.
$report = json_decode(file_get_contents($argv[1] ?? __DIR__.'/audit.json'), true, 512, JSON_THROW_ON_ERROR);
$manifest = json_decode(file_get_contents(__DIR__.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$allowed = array_keys($manifest['config']['policy']['advisories']['ignore-id']);

if (!is_array($report) || !isset($report['advisories']) || !is_array($report['advisories'])) {
    throw new RuntimeException('Composer did not produce a valid advisory report.');
}

$failed = !empty($report['abandoned']);
foreach ($report['advisories'] as $package => $advisories) {
    foreach ($advisories as $advisory) {
        $id = $advisory['advisoryId'] ?? '';
        echo $package.': '.$id.' — '.($advisory['title'] ?? 'Unknown advisory')."\n";
        if ($package !== 'laravel/framework' || !in_array($id, $allowed, true)) {
            $failed = true;
        }
    }
}

if ($failed) {
    fwrite(STDERR, "Unexpected advisory or abandoned dependency; review the compatibility fixture.\n");
    exit(1);
}
