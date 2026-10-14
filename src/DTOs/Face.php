<?php

declare(strict_types=1);

namespace Shaykhnazar\HikvisionIsapi\DTOs;

use Shaykhnazar\HikvisionIsapi\DTOs\Concerns\HydratesDeviceValues;

final readonly class Face
{
    use HydratesDeviceValues;

    public function __construct(
        public string $employeeNo,
        #[\SensitiveParameter] public string $faceData,
        public ?int $faceLibId = 1,
        public ?string $faceLibType = 'blackFD'
    ) {}

    public function toArray(): array
    {
        return [
            'faceInfo' => [
                'employeeNo' => $this->employeeNo,
                'faceLibType' => $this->faceLibType,
            ],
            'faceData' => $this->faceData,
        ];
    }

    public static function fromArray(#[\SensitiveParameter] array $data): self
    {
        $faceInfo = $data['faceInfo'] ?? $data;

        return new self(
            employeeNo: self::deviceString($faceInfo['employeeNo'] ?? ''),
            faceData: self::deviceString($data['faceData'] ?? ''),
            faceLibId: self::deviceInteger(array_key_exists('faceLibId', $faceInfo) ? $faceInfo['faceLibId'] : 1),
            faceLibType: self::deviceNullableString($faceInfo['faceLibType'] ?? 'blackFD')
        );
    }
}
