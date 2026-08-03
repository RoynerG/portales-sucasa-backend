<?php

namespace Tests\Unit;

use App\Services\Portals\FincaraizClient;
use App\Services\Portals\FincaraizListingReconciler;
use App\Services\Portals\FincaraizListingRetirer;
use App\Services\WordPressPropertyRepository;
use Illuminate\Support\Collection;
use Tests\TestCase;

class FincaraizListingRetirerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('portals.fincaraiz.environment', 'production');
    }

    public function test_preview_only_marks_non_public_exact_active_matches_as_ready(): void
    {
        $client = $this->createMock(FincaraizClient::class);
        $client->expects($this->once())->method('listListings')->willReturn([
            'ok' => true,
            'data' => [
                'results' => [
                    ['id' => '11111111-1111-4111-8111-111111111111', 'frPropertyId' => '9001', 'status' => 4],
                    ['id' => '22222222-2222-4222-8222-222222222222', 'frPropertyId' => '9002', 'status' => 4],
                    ['id' => '33333333-3333-4333-8333-333333333333', 'frPropertyId' => '9003', 'status' => 1],
                ],
                'next' => null,
            ],
        ]);
        $wordpress = $this->createMock(WordPressPropertyRepository::class);
        $wordpress->method('activeCodes')->willReturn(new Collection(['100']));

        $result = (new FincaraizListingRetirer($client, $wordpress))->preview([
            'api_key' => 'secret',
            'client_id' => 'client',
        ], [
            ['code' => '100', 'fr_property_id' => '9001'],
            ['code' => '200', 'fr_property_id' => '9002'],
            ['code' => '300', 'fr_property_id' => '9003'],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['ready']);
        $this->assertSame(1, $result['linkable']);
        $this->assertSame(1, $result['protected']);
        $this->assertSame(1, $result['review']);
        $this->assertSame('ready_to_link', $result['items'][0]['state']);
        $this->assertSame('ready', $result['items'][1]['state']);
        $this->assertSame('not_active', $result['items'][2]['state']);
    }

    public function test_apply_rechecks_the_local_catalog_before_disabling(): void
    {
        $client = $this->createMock(FincaraizClient::class);
        $client->expects($this->once())
            ->method('changeStatusesMany')
            ->with(
                ['33333333-3333-4333-8333-333333333333'],
                'DISABLED',
                'client',
                'secret',
                4
            )
            ->willReturn([
                '33333333-3333-4333-8333-333333333333' => [
                    'ok' => true,
                    'data' => ['task' => ['id' => 'task-300']],
                ],
            ]);
        $wordpress = $this->createMock(WordPressPropertyRepository::class);
        $wordpress->method('activeCodes')->willReturn(new Collection(['200']));
        $reconciler = $this->createMock(FincaraizListingReconciler::class);
        $reconciler->expects($this->once())->method('applyPreview')->willReturn([
            'items' => [[
                'code' => '200',
                'fr_property_id' => '9002',
                'listing_id' => '22222222-2222-4222-8222-222222222222',
                'state' => 'linked',
            ]],
        ]);

        $result = (new FincaraizListingRetirer($client, $wordpress, $reconciler))->apply([
            'api_key' => 'secret',
            'client_id' => 'client',
        ], [
            [
                'code' => '200',
                'fr_property_id' => '9002',
                'listing_id' => '22222222-2222-4222-8222-222222222222',
                'state' => 'ready_to_link',
            ],
            [
                'code' => '300',
                'fr_property_id' => '9003',
                'listing_id' => '33333333-3333-4333-8333-333333333333',
                'state' => 'ready',
            ],
        ]);

        $this->assertSame(1, $result['queued']);
        $this->assertSame(1, $result['linked']);
        $this->assertSame(1, $result['protected']);
        $this->assertSame(0, $result['errors']);
        $this->assertSame('linked', $result['items'][0]['state']);
        $this->assertSame('queued', $result['items'][1]['state']);
        $this->assertSame('task-300', $result['items'][1]['task_id']);
    }

    public function test_preview_counts_active_listing_ids_inside_ambiguous_review_rows(): void
    {
        $client = $this->createMock(FincaraizClient::class);
        $client->expects($this->once())->method('listListings')->willReturn([
            'ok' => true,
            'data' => [
                'results' => [
                    ['id' => '11111111-1111-4111-8111-111111111111', 'frPropertyId' => '9001', 'status' => 4],
                    ['id' => '22222222-2222-4222-8222-222222222222', 'frPropertyId' => '9001', 'status' => 4],
                ],
                'next' => null,
            ],
        ]);
        $wordpress = $this->createMock(WordPressPropertyRepository::class);
        $wordpress->method('activeCodes')->willReturn(new Collection(['100']));

        $result = (new FincaraizListingRetirer($client, $wordpress))->preview([
            'api_key' => 'secret',
            'client_id' => 'client',
        ], [
            ['code' => '100', 'fr_property_id' => '9001'],
            ['code' => '200', 'fr_property_id' => '9002'],
        ]);

        $this->assertSame(2, $result['review']);
        $this->assertSame(1, $result['removable_codes']);
        $this->assertSame(2, $result['removable_listings']);
        $this->assertSame('protected_unlinked', $result['items'][0]['state']);
        $this->assertCount(2, $result['items'][0]['listing_ids']);
        $this->assertSame([], $result['items'][1]['listing_ids']);
    }

    public function test_apply_unresolved_disables_each_unique_active_uuid(): void
    {
        $client = $this->createMock(FincaraizClient::class);
        $client->expects($this->once())
            ->method('changeStatusesMany')
            ->with(
                [
                    '11111111-1111-4111-8111-111111111111',
                    '22222222-2222-4222-8222-222222222222',
                ],
                'DISABLED',
                'client',
                'secret',
                4
            )
            ->willReturn([
                '11111111-1111-4111-8111-111111111111' => ['ok' => true, 'data' => ['task' => ['id' => 'task-1']]],
                '22222222-2222-4222-8222-222222222222' => ['ok' => true, 'data' => ['task' => ['id' => 'task-2']]],
            ]);
        $wordpress = $this->createMock(WordPressPropertyRepository::class);

        $result = (new FincaraizListingRetirer($client, $wordpress))->applyUnresolved([
            'api_key' => 'secret',
            'client_id' => 'client',
        ], [
            [
                'code' => '100',
                'fr_property_id' => '9001',
                'state' => 'protected_unlinked',
                'listing_ids' => [
                    '11111111-1111-4111-8111-111111111111',
                    '22222222-2222-4222-8222-222222222222',
                ],
            ],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('unresolved', $result['mode']);
        $this->assertSame(1, $result['requested_codes']);
        $this->assertSame(2, $result['requested_listings']);
        $this->assertSame(2, $result['queued']);
        $this->assertSame(0, $result['errors']);
    }
}
