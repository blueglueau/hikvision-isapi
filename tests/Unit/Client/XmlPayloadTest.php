<?php

declare(strict_types=1);

namespace Shaykhnazar\HikvisionIsapi\Tests\Unit\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shaykhnazar\HikvisionIsapi\Client\HttpClient;

class XmlPayloadTest extends TestCase
{
    private function serialize(array $payload): \SimpleXMLElement
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, ['Content-Type' => 'application/xml'], '<ResponseStatus/>')]));
        $stack->push(Middleware::history($history));
        $client = new HttpClient(new Client(['handler' => $stack]));
        $client->put('http://device.local/mock', $payload, ['_format' => 'xml']);

        return new \SimpleXMLElement((string) $history[0]['request']->getBody());
    }

    public function test_object_lists_become_repeated_named_elements(): void
    {
        $xml = $this->serialize(['UserInfo' => ['RightPlan' => [
            ['doorNo' => 1, 'planTemplateNo' => '001'],
            ['doorNo' => 2, 'planTemplateNo' => '002'],
        ]]]);

        $this->assertCount(2, $xml->RightPlan);
        $this->assertSame('001', (string) $xml->RightPlan[0]->planTemplateNo);
        $this->assertSame('2', (string) $xml->RightPlan[1]->doorNo);
        $this->assertSame('http://www.isapi.org/ver20/XMLSchema', $xml->getDocNamespaces()['']);
    }

    public function test_scalar_lists_preserve_the_named_container(): void
    {
        $xml = $this->serialize(['HttpHostNotification' => ['eventList' => ['eventType' => ['AccessControllerEvent', 'videoloss']]]]);

        $this->assertCount(2, $xml->eventList->eventType);
        $this->assertSame('videoloss', (string) $xml->eventList->eventType[1]);
    }

    public function test_attributes_booleans_and_text_survive_a_round_trip(): void
    {
        $xml = $this->serialize(['HttpHostNotification' => [
            '@attributes' => ['version' => '1.0'],
            'enabled' => false,
            'url' => '/receive?a=1&b=2',
            'eventList' => ['@attributes' => ['size' => '1'], 'eventType' => ['AccessControllerEvent']],
        ]]);

        $this->assertSame('1.0', (string) $xml['version']);
        $this->assertSame('1', (string) $xml->eventList['size']);
        $this->assertSame('false', (string) $xml->enabled);
        $this->assertSame('/receive?a=1&b=2', (string) $xml->url);
    }
}
