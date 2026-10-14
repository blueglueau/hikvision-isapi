<?php

declare(strict_types=1);

namespace Shaykhnazar\HikvisionIsapi\DTOs;

use Shaykhnazar\HikvisionIsapi\DTOs\Concerns\HydratesDeviceValues;
use Shaykhnazar\HikvisionIsapi\Enums\UserType;

final readonly class Person
{
    use HydratesDeviceValues;

    public function __construct(
        public string $employeeNo,
        public string $name,
        public UserType $userType,
        public bool $validEnabled,
        public ?string $beginTime = null,
        public ?string $endTime = null,
        public ?string $doorRight = null,
        public array $rightPlan = [],
        public ?string $email = null,
        public ?string $phoneNumber = null,
        public ?int $organizationId = null,
        public ?array $belongGroup = null
    ) {}

    public function toArray(): array
    {
        // Build Valid object - all fields are required
        $valid = ['enable' => $this->validEnabled];
        if ($this->beginTime !== null) {
            $valid['beginTime'] = $this->beginTime;
        }
        if ($this->endTime !== null) {
            $valid['endTime'] = $this->endTime;
        }

        $userInfo = [
            'employeeNo' => $this->employeeNo,
            'name' => $this->name,
            'userType' => $this->userType->value,
            'Valid' => $valid,
        ];

        // Add optional fields only if they have values
        if ($this->doorRight !== null) {
            $userInfo['doorRight'] = $this->doorRight;
        }

        if (!empty($this->rightPlan)) {
            $userInfo['RightPlan'] = $this->rightPlan;
        }

        if ($this->email !== null) {
            $userInfo['email'] = $this->email;
        }

        if ($this->phoneNumber !== null) {
            $userInfo['phoneNumber'] = $this->phoneNumber;
        }

        if ($this->organizationId !== null) {
            $userInfo['organizationId'] = $this->organizationId;
        }

        if ($this->belongGroup !== null) {
            $userInfo['belongGroup'] = $this->belongGroup;
        }

        return ['UserInfo' => $userInfo];
    }

    public static function fromArray(#[\SensitiveParameter] array $data): self
    {
        $userInfo = $data['UserInfo'] ?? $data;

        // Handle belongGroup - API might return string or array
        $belongGroup = null;
        if (isset($userInfo['belongGroup'])) {
            if (is_array($userInfo['belongGroup'])) {
                $belongGroup = $userInfo['belongGroup'];
            } elseif (is_string($userInfo['belongGroup']) && !empty($userInfo['belongGroup'])) {
                // Convert string to array (might be comma-separated or single value)
                $belongGroup = [$userInfo['belongGroup']];
            }
        }

        return new self(
            employeeNo: self::deviceString($userInfo['employeeNo'] ?? ''),
            name: self::deviceString($userInfo['name'] ?? ''),
            userType: UserType::from($userInfo['userType'] ?? 'normal'),
            validEnabled: self::deviceBoolean($userInfo['Valid']['enable'] ?? true),
            beginTime: self::deviceNullableString($userInfo['Valid']['beginTime'] ?? null),
            endTime: self::deviceNullableString($userInfo['Valid']['endTime'] ?? null),
            doorRight: self::deviceNullableString($userInfo['doorRight'] ?? null),
            rightPlan: isset($userInfo['RightPlan']) && !array_is_list($userInfo['RightPlan']) ? [$userInfo['RightPlan']] : ($userInfo['RightPlan'] ?? []),
            email: self::deviceNullableString($userInfo['email'] ?? null),
            phoneNumber: self::deviceNullableString($userInfo['phoneNumber'] ?? null),
            organizationId: self::deviceInteger($userInfo['organizationId'] ?? null),
            belongGroup: $belongGroup
        );
    }
}
