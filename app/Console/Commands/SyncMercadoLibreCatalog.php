<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Models\PortalCredential;
use App\Services\Portals\MercadoLibreCatalogService;
use Illuminate\Console\Command;

class SyncMercadoLibreCatalog extends Command
{
    protected $signature = 'mercadolibre:sync-catalog';

    protected $description = 'Sincroniza categorías hoja y atributos de Inmuebles MCO';

    public function handle(MercadoLibreCatalogService $catalog): int
    {
        $integration = Integration::where('slug', 'mercadolibre')->first();
        $credential = $integration
            ? PortalCredential::where([
                'integration_id' => $integration->id,
                'account_key' => config('portals.mercadolibre.account_key'),
            ])->first()
            : null;
        if (! $credential) {
            $this->warn('Mercado Libre no está conectado; se omite la sincronización.');
            return self::SUCCESS;
        }

        $result = $catalog->sync($credential);
        $this->info("Sincronizadas {$result['categories']} categorías y {$result['mappings']} homologaciones.");

        return self::SUCCESS;
    }
}
