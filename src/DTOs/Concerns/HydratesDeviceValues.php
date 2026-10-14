<?php

declare(strict_types=1);

namespace Shaykhnazar\HikvisionIsapi\DTOs\Concerns;

use Shaykhnazar\HikvisionIsapi\Exceptions\InvalidResponseException;

trait HydratesDeviceValues
{
    private static function deviceBoolean(#[\SensitiveParameter] mixed $value): bool
    {
        return match (is_string($value) ? strtolower(trim($value)) : $value) {
            true, 1, '1', 'true' => true,
            false, 0, '0', 'false' => false,
            default => throw new InvalidResponseException('Device returned an invalid boolean field.'),
        };
    }

    private static function deviceInteger(#[\SensitiveParameter] mixed $value): ?int
    {
        if ($value === null || is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[+-]?\d+$/D', trim($value))) {
            // XML integers are decimal even when firmware includes leading zeros.
            $normalized = preg_replace('/^([+-]?)0+(?=\d)/', '$1', trim($value));
            $integer = filter_var($normalized, FILTER_VALIDATE_INT);
            if ($integer !== false) {
                return $integer;
            }
        }

        throw new InvalidResponseException('Device returned an invalid integer field.');
    }
}
