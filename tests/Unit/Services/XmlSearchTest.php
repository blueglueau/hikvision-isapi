<?php

declare(strict_types=1);

namespace Shaykhnazar\HikvisionIsapi\Tests\Unit\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shaykhnazar\HikvisionIsapi\Authentication\DigestAuthenticator;
use Shaykhnazar\HikvisionIsapi\Client\HikvisionClient;
use Shaykhnazar\HikvisionIsapi\Client\HttpClient;
use Shaykhnazar\HikvisionIsapi\Services\CardService;
use Shaykhnazar\HikvisionIsapi\Services\EventService;
use Shaykhnazar\HikvisionIsapi\Services\PersonService;

class XmlSearchTest extends TestCase
{
    private function client(string ...$responses): HikvisionClient
    {
        $queue = array_map(fn (string $xml) => new Response(200, ['Content-Type' => 'application/xml'], $xml), $responses);

        return new HikvisionClient(new HttpClient(new Client(['handler' => HandlerStack::create(new MockHandler($queue))])), new DigestAuthenticator, [
            'default' => 'primary', 'format' => 'xml',
            'devices' => ['primary' => ['ip' => 'device.local', 'port' => 80, 'protocol' => 'http', 'username' => 'admin', 'password' => 'fixture', 'timeout' => 1, 'verify_ssl' => false]],
        ]);
    }

    public function test_single_person_xml_response_is_hydrated(): void
    {
        $service = new PersonService($this->client('<UserInfoSearch><UserInfo><employeeNo>001</employeeNo><name>Review</name><userType>normal</userType><Valid><enable>false</enable></Valid><organizationId>7</organizationId></UserInfo></UserInfoSearch>'));
        $people = $service->search();

        $this->assertCount(1, $people);
        $this->assertSame('001', $people[0]->employeeNo);
        $this->assertFalse($people[0]->validEnabled);
        $this->assertSame(7, $people[0]->organizationId);
    }

    public function test_multiple_card_xml_records_are_hydrated(): void
    {
        $service = new CardService($this->client('<CardInfoSearch><CardInfo><employeeNo>001</employeeNo><cardNo>0001</cardNo><enabled>false</enabled></CardInfo><CardInfo><employeeNo>002</employeeNo><cardNo>0002</cardNo><enabled>true</enabled></CardInfo></CardInfoSearch>'));
        $cards = $service->search();

        $this->assertCount(2, $cards);
        $this->assertSame('0001', $cards[0]->cardNo);
        $this->assertFalse($cards[0]->enabled);
        $this->assertTrue($cards[1]->enabled);
    }

    public function test_xml_pagination_reads_the_unwrapped_status(): void
    {
        $service = new PersonService($this->client(
            '<UserInfoSearch><responseStatusStrg>MORE</responseStatusStrg><UserInfo><employeeNo>001</employeeNo></UserInfo></UserInfoSearch>',
            '<UserInfoSearch><responseStatusStrg>OK</responseStatusStrg><UserInfo><employeeNo>002</employeeNo></UserInfo></UserInfoSearch>',
        ));

        $this->assertSame(['001', '002'], array_map(fn ($person) => $person->employeeNo, iterator_to_array($service->all())));
    }

    public function test_xml_counts_accept_integer_strings(): void
    {
        $this->assertSame(12, (new PersonService($this->client('<UserInfoCount><userNumber>12</userNumber></UserInfoCount>')))->count());
        $this->assertSame(3, (new CardService($this->client('<CardInfo><cardNumber>3</cardNumber></CardInfo>')))->count());
    }

    public function test_event_xml_pagination_reads_unwrapped_records(): void
    {
        $service = new EventService($this->client('<AcsEvent><responseStatusStrg>OK</responseStatusStrg><InfoList><employeeNoString>001</employeeNoString></InfoList></AcsEvent>'));
        $events = iterator_to_array($service->between(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-02')));

        $this->assertSame([['employeeNoString' => '001']], $events);
    }
}
