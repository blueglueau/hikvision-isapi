<?php

declare(strict_types=1);

namespace Shaykhnazar\HikvisionIsapi\Tests\Unit\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shaykhnazar\HikvisionIsapi\Client\HttpClient;
use Shaykhnazar\HikvisionIsapi\Exceptions\DeviceBusyException;
use Shaykhnazar\HikvisionIsapi\Exceptions\HikvisionException;
use Shaykhnazar\HikvisionIsapi\Exceptions\InvalidResponseException;

class ResponseValidationTest extends TestCase
{
    private function request(string $body, string $type = 'application/json', int $status = 200): array
    {
        $client = new HttpClient(new Client(['handler' => HandlerStack::create(new MockHandler([new Response($status, ['Content-Type' => $type], $body)]))]));

        return $client->get('http://device.local/mock');
    }

    public static function invalidJson(): array
    {
        return [['{"broken":'], ['true'], ['null'], ['"text"'], ['123'], ['']];
    }

    #[DataProvider('invalidJson')]
    public function test_invalid_json_raises_a_domain_exception(string $body): void
    {
        try {
            $this->request($body);
            $this->fail('Expected an invalid response exception.');
        } catch (InvalidResponseException $exception) {
            $this->assertSame(200, $exception->statusCode());
            $this->assertSame($body, $exception->responseBody());
            $this->assertFalse($exception->isRetryable());
        }
    }

    public function test_json_objects_arrays_and_no_content_are_valid(): void
    {
        $this->assertSame([], $this->request('{}'));
        $this->assertSame([['employeeNo' => '001']], $this->request('[{"employeeNo":"001"}]'));
        $this->assertSame([], $this->request('', status: 204));
        $this->assertSame(['employeeNo' => '9223372036854775808'], $this->request('{"employeeNo":9223372036854775808}'));
    }

    public static function deviceFailures(): array
    {
        return [
            ['{"statusCode":4,"statusString":"Invalid Operation","subStatusCode":"notSupport"}', 'application/json'],
            ['{"ResponseStatus":{"statusCode":4}}', 'application/json'],
            ['<ResponseStatus><statusCode>4</statusCode><statusString>Invalid Operation</statusString></ResponseStatus>', 'application/xml'],
        ];
    }

    #[DataProvider('deviceFailures')]
    public function test_http_success_does_not_hide_device_failure(string $body, string $type): void
    {
        try {
            $this->request($body, $type);
            $this->fail('Expected a device error.');
        } catch (HikvisionException $exception) {
            $this->assertSame(200, $exception->statusCode());
            $this->assertSame($body, $exception->responseBody());
            $this->assertFalse($exception->isRetryable());
        }
    }

    public function test_device_busy_status_is_retryable(): void
    {
        $this->expectException(DeviceBusyException::class);
        $this->request('<ResponseStatus><statusCode>2</statusCode></ResponseStatus>', 'text/xml');
    }

    public function test_status_fields_in_data_are_not_error_envelopes(): void
    {
        $this->assertSame(['statusCode' => 4, 'name' => 'data'], $this->request('{"statusCode":4,"name":"data"}'));
        $this->assertSame(['statusCode' => '4'], $this->request('<DeviceInfo><statusCode>4</statusCode></DeviceInfo>', 'application/xml'));
        $this->assertSame(['statusCode' => '7'], $this->request('<ResponseStatus><statusCode>7</statusCode></ResponseStatus>', 'application/xml'));
    }
}
