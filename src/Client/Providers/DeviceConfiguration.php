<?php

declare(strict_types=1);

namespace Shaykhnazar\HikvisionIsapi\Client\Providers;

/** Maps raw table records and Eloquent attributes to the same client contract. */
final class DeviceConfiguration
{
    public const COLUMNS = [
        'ip' => 'ip', 'port' => 'port', 'username' => 'username', 'password' => 'password',
        'protocol' => 'protocol', 'timeout' => 'timeout', 'verify_ssl' => 'verify_ssl',
    ];

    private const DEFAULTS = ['port' => 80, 'username' => 'admin', 'protocol' => 'http', 'timeout' => 30, 'verify_ssl' => false];

    public static function fromRecord(#[\SensitiveParameter] object|array $record, array $columns = []): array
    {
        $config = [];
        foreach (array_replace(self::COLUMNS, $columns) as $key => $column) {
            // Property access deliberately preserves Eloquent casts/accessors,
            // including decryption of device passwords on model providers.
            $value = is_array($record) ? ($record[$column] ?? null) : ($record->{$column} ?? null);
            if ($key === 'ip' && $column === 'ip' && $value === null) {
                $value = is_array($record) ? ($record['ip_address'] ?? null) : ($record->ip_address ?? null);
            }
            $value ??= self::DEFAULTS[$key] ?? null;

            if ($key === 'verify_ssl') {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($value === null) {
                    throw new \InvalidArgumentException('Device verify_ssl must be a boolean.');
                }
            } elseif (in_array($key, ['port', 'timeout'], true)) {
                $value = filter_var($value, FILTER_VALIDATE_INT);
                if ($value === false) {
                    throw new \InvalidArgumentException("Device {$key} must be an integer.");
                }
            }

            $config[$key] = $value;
        }

        return $config;
    }
}
