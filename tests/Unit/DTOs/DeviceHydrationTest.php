<?php

declare(strict_types=1);

namespace Shaykhnazar\HikvisionIsapi\Tests\Unit\DTOs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shaykhnazar\HikvisionIsapi\DTOs\Card;
use Shaykhnazar\HikvisionIsapi\DTOs\Face;
use Shaykhnazar\HikvisionIsapi\DTOs\Person;
use Shaykhnazar\HikvisionIsapi\Exceptions\InvalidResponseException;

class DeviceHydrationTest extends TestCase
{
    public static function booleanValues(): array
    {
        return [['true', true], ['false', false], ['1', true], ['0', false], [true, true], [false, false], [1, true], [0, false]];
    }

    #[DataProvider('booleanValues')]
    public function test_device_booleans_are_normalized(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, Person::fromArray(['Valid' => ['enable' => $value]])->validEnabled);
        $this->assertSame($expected, Card::fromArray(['enabled' => $value])->enabled);
    }

    public function test_numeric_fields_are_normalized_without_changing_identifiers(): void
    {
        $person = Person::fromArray(['employeeNo' => '000123', 'organizationId' => '007', 'RightPlan' => ['doorNo' => '1', 'planTemplateNo' => '001']]);
        $this->assertSame('000123', $person->employeeNo);
        $this->assertSame(7, $person->organizationId);
        $this->assertSame([['doorNo' => '1', 'planTemplateNo' => '001']], $person->rightPlan);
        $this->assertSame(2, Face::fromArray(['faceInfo' => ['faceLibId' => '2']])->faceLibId);
        $this->assertNull(Face::fromArray(['faceInfo' => ['faceLibId' => null]])->faceLibId);
        $this->assertSame('000045', Card::fromArray(['cardNo' => '000045'])->cardNo);
    }

    public function test_unknown_booleans_fail_explicitly(): void
    {
        $this->expectException(InvalidResponseException::class);
        Person::fromArray(['Valid' => ['enable' => 'not-a-boolean']]);
    }

    public function test_invalid_integers_fail_explicitly(): void
    {
        $this->expectException(InvalidResponseException::class);
        Face::fromArray(['faceInfo' => ['faceLibId' => '2.5']]);
    }

    public function test_empty_xml_fields_and_numeric_identifiers_are_normalized(): void
    {
        $person = Person::fromArray(['employeeNo' => 123, 'name' => [], 'Valid' => ['beginTime' => []], 'email' => [], 'organizationId' => []]);
        $this->assertSame('123', $person->employeeNo);
        $this->assertSame('', $person->name);
        $this->assertSame('', $person->beginTime);
        $this->assertSame('', $person->email);
        $this->assertNull($person->organizationId);
        $this->assertSame('', Card::fromArray(['cardType' => []])->cardType);
        $this->assertSame('', Face::fromArray(['faceData' => []])->faceData);
    }
}
