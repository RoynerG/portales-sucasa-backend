<?php

namespace Tests\Unit;

use App\Services\Portals\FincaraizClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class FincaraizClientTest extends TestCase
{
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('portals.fincaraiz.api_url', 'https://fincaraiz.test/management/api/1.0');
        config()->set('portals.fincaraiz.cache_buster_name', 'sucasa-cache');
    }

    public function test_create_listing_uses_the_apikey_header_and_a_batch_body(): void
    {
        $client = $this->clientWith([
            new Response(200, [], json_encode([
                'task' => ['id' => 'df03d199-be5c-4c5c-98f6-849361cb7fae', 'status' => 'READY'],
            ])),
        ]);

        $result = $client->createListing(['external_code' => '53824'], 'secret-key');

        $this->assertTrue($result['ok']);
        $request = $this->history[0]['request'];
        $this->assertSame('secret-key', $request->getHeaderLine('apikey'));
        $this->assertSame('', $request->getUri()->getQuery());
        $this->assertSame([['external_code' => '53824']], json_decode((string) $request->getBody(), true));
    }

    public function test_listing_queries_use_cookie_header_and_dynamic_cache_buster(): void
    {
        $client = $this->clientWith([
            new Response(200, [], json_encode(['results' => []])),
        ]);

        $result = $client->listListings('secret-key', 'client-uuid', 2, 10, '53824');

        $this->assertTrue($result['ok']);
        $request = $this->history[0]['request'];
        parse_str($request->getUri()->getQuery(), $query);
        $this->assertSame('client-uuid', $request->getHeaderLine('Cookie'));
        $this->assertSame('2', $query['page']);
        $this->assertSame('10', $query['page_size']);
        $this->assertSame('53824', $query['search']);
        $this->assertNotEmpty($query['sucasa-cache']);
    }

    public function test_non_success_http_status_is_not_reported_as_ok(): void
    {
        $client = $this->clientWith([
            new Response(401, [], json_encode(['detail' => 'Invalid API key'])),
        ]);

        $result = $client->getClients('invalid');

        $this->assertFalse($result['ok']);
        $this->assertSame(401, $result['status']);
        $this->assertSame('Invalid API key', $result['data']['detail']);
    }

    private function clientWith(array $responses): FincaraizClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new FincaraizClient(new Client(['handler' => $stack, 'http_errors' => false]));
    }
}
