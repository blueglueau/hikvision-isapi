<?php

declare(strict_types=1);

namespace Shaykhnazar\HikvisionIsapi\Tests\Unit\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shaykhnazar\HikvisionIsapi\Authentication\DigestAuthenticator;
use Shaykhnazar\HikvisionIsapi\Client\HikvisionClient;
use Shaykhnazar\HikvisionIsapi\Client\HttpClient;
use Shaykhnazar\HikvisionIsapi\Services\EventNotificationService;

class EventNotificationServiceTest extends TestCase
{
    private array $history = [];

    private function service(array $responses): EventNotificationService
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new EventNotificationService(new HikvisionClient(new HttpClient(new Client(['handler' => $stack])), new DigestAuthenticator, [
            'default' => 'primary', 'format' => 'json',
            'devices' => ['primary' => ['ip' => 'device.local', 'port' => 80, 'protocol' => 'http', 'username' => 'admin', 'password' => 'fixture', 'timeout' => 1, 'verify_ssl' => false]],
        ]));
    }

    private function ok(): Response
    {
        return new Response(200, ['Content-Type' => 'application/xml'], '<ResponseStatus><statusCode>1</statusCode></ResponseStatus>');
    }

    public function test_webhook_preserves_hostname_port_query_and_event_filters(): void
    {
        $this->service([$this->ok()])->configureWebhook('https://events.example.com:8443/receive?tenant=001', 2, ['AccessControllerEvent', 'videoloss']);
        $xml = new \SimpleXMLElement((string) $this->history[0]['request']->getBody());

        $this->assertSame('hostname', (string) $xml->addressingFormatType);
        $this->assertSame('events.example.com', (string) $xml->hostName);
        $this->assertFalse(isset($xml->ipAddress));
        $this->assertSame('8443', (string) $xml->portNo);
        $this->assertSame('HTTPS', (string) $xml->protocolType);
        $this->assertSame('/receive?tenant=001', (string) $xml->url);
        $this->assertCount(2, $xml->eventList->eventType);
    }

    public function test_ip_addresses_use_the_ip_field(): void
    {
        $this->service([$this->ok()])->configureWebhook('http://192.0.2.1/receive');
        $xml = new \SimpleXMLElement((string) $this->history[0]['request']->getBody());

        $this->assertSame('192.0.2.1', (string) $xml->ipAddress);
        $this->assertFalse(isset($xml->hostName));
    }

    public static function toggles(): array
    {
        return [['enableHttpHost', 'true'], ['disableHttpHost', 'false']];
    }

    #[DataProvider('toggles')]
    public function test_xml_host_configuration_can_be_toggled(string $method, string $expected): void
    {
        $service = $this->service([
            new Response(200, ['Content-Type' => 'application/xml'], '<HttpHostNotification xmlns="http://www.isapi.org/ver20/XMLSchema" version="1.0"><id>1</id><url>/receive</url><enabled>false</enabled></HttpHostNotification>'),
            $this->ok(),
        ]);
        $service->$method();
        $xml = new \SimpleXMLElement((string) $this->history[1]['request']->getBody());

        $this->assertSame('HttpHostNotification', $xml->getName());
        $this->assertSame('1.0', (string) $xml['version']);
        $this->assertSame($expected, (string) $xml->enabled);
        $this->assertSame('/receive', (string) $xml->url);
    }

    public function test_invalid_webhook_urls_are_rejected_before_transport(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service([])->configureWebhook('not a URL');
    }
}
