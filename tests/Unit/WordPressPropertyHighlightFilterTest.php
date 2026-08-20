<?php

namespace Tests\Unit;

use App\Services\WordPressHighlightService;
use App\Services\WordPressPropertyRepository;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WordPressPropertyHighlightFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.connections.wordpress', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('wordpress');

        Schema::connection('wordpress')->create('wp_jet_cct_inmuebles', function (Blueprint $table): void {
            $table->increments('_ID');
            $table->string('cct_status')->default('publish');
            $table->string('codigo');
            $table->string('destacado')->nullable();
            $table->string('marcado_destacado')->nullable();
            foreach (WordPressHighlightService::MARKETS as $market) {
                $table->string($market['property_column'])->nullable();
            }
        });

        $emptyMarkets = collect(WordPressHighlightService::MARKETS)
            ->mapWithKeys(fn (array $market): array => [$market['property_column'] => 'No'])
            ->all();

        DB::connection('wordpress')->table('wp_jet_cct_inmuebles')->insert([
            [...$emptyMarkets, 'codigo' => '100', 'destacado' => 'Si', 'mercado_libre_destacado' => 'Si'],
            [...$emptyMarkets, 'codigo' => '200', 'destacado' => 'Si', 'proppit_promocionado' => 'Si', 'finca_raiz_gold' => 'Si'],
            [...$emptyMarkets, 'codigo' => '300', 'destacado' => 'No'],
        ]);
    }

    public function test_it_filters_public_catalog_by_highlight_market(): void
    {
        $repository = new class extends WordPressPropertyRepository
        {
            public function codes(array $filters): array
            {
                $query = $this->baseQuery();
                $this->applyFilters($query, $filters);

                return $query->orderBy('codigo')->pluck('codigo')->all();
            }
        };

        $this->assertSame(['100'], $repository->codes(['mercado_destacado' => 'mercado_libre_destacados']));
        $this->assertSame(['200'], $repository->codes(['mercado_destacado' => 'proppit_promocionados']));
        $this->assertSame(['200'], $repository->codes(['mercado_destacado' => 'finca_raiz_gold']));
        $this->assertSame([], $repository->codes(['mercado_destacado' => 'portal_invalido']));
    }
}
