<?php

declare(strict_types=1);

namespace Shaykhnazar\HikvisionIsapi\DTOs;

use Shaykhnazar\HikvisionIsapi\DTOs\Concerns\HydratesDeviceValues;

final readonly class Card
{
    use HydratesDeviceValues;

    public function __construct(
        public string $employeeNo,
        public string $cardNo,
        public ?string $cardType = null,
        public bool $enabled = true
    ) {}

    public function toArray(): array
    {
        return [
            'CardInfo' => [
                'employeeNo' => $this->employeeNo,
                'cardNo' => $this->cardNo,
                'cardType' => $this->cardType,
            ],
        ];
    }

    public static function fromArray(#[\SensitiveParameter] array $data): self
    {
        $cardInfo = $data['CardInfo'] ?? $data;

        return new self(
            employeeNo: self::deviceString($cardInfo['employeeNo'] ?? ''),
            cardNo: self::deviceString($cardInfo['cardNo'] ?? ''),
            cardType: self::deviceNullableString($cardInfo['cardType'] ?? null),
            enabled: self::deviceBoolean($cardInfo['enabled'] ?? true)
        );
    }
}
