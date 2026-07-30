<?php

namespace Tests\Unit;

use App\Services\Portals\ProppitClient;
use App\Services\Portals\ProppitPropertyMapper;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class ProppitClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('portals.proppit.api_url', 'https://real-time.proppit.test/api/v2');
        config()->set('portals.proppit.country', 'CO');
    }

    public function test_reference_id_preserves_the_original_property_code_without_prefixes(): void
    {
        $mapper = new ProppitPropertyMapper;

        $this->assertSame('53824', $mapper->referenceId(' 53824 '));
        $this->assertSame('SC-53824', $mapper->referenceId('SC-53824'));
    }

    public function test_get_publisher_returns_its_activation_state(): void
    {
        $client = $this->clientWith([
            new Response(200, [], json_encode([
                'id' => 'sucasa',
                'publishingEnabled' => true,
            ])),
        ]);

        $result = $client->getPublisher('sucasa', 'token');

        $this->assertTrue($result['ok']);
        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['data']['publishingEnabled']);
    }

    public function test_get_publisher_preserves_proppit_request_id_on_404(): void
    {
        $client = $this->clientWith([
            new Response(404, [], json_encode([
                'status' => 404,
                'requestId' => 'request-123',
                'error' => 'Publisher not found',
            ])),
        ]);

        $result = $client->getPublisher('missing', 'token');

        $this->assertFalse($result['ok']);
        $this->assertSame(404, $result['data']['status']);
        $this->assertSame('Publisher not found', $result['data']['body']['error']);
        $this->assertSame('request-123', $result['data']['body']['requestId']);
    }

    public function test_create_publisher_posts_the_expected_payload(): void
    {
        $client = $this->clientWith([
            new Response(201, [], json_encode([
                'id' => 'sucasa',
                'name' => 'Su Casa Inmobiliaria',
                'publishingEnabled' => false,
            ])),
        ]);

        $result = $client->createPublisher([
            'id' => 'sucasa',
            'name' => 'Su Casa Inmobiliaria',
        ], 'token');

        $this->assertTrue($result['ok']);
        $this->assertSame(201, $result['status']);
        $this->assertSame('sucasa', $result['data']['id']);
        $this->assertFalse($result['data']['publishingEnabled']);
    }

    private function clientWith(array $responses): ProppitClient
    {
        $handler = new MockHandler($responses);
        $stack = HandlerStack::create($handler);

        return new ProppitClient(new Client([
            'handler' => $stack,
            'http_errors' => true,
        ]));
    }
}
