<?php

namespace App\Console\Commands;

use App\Models\PortalCredential;
use App\Services\Portals\CiencuadrasClient;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuditCiencuadrasLegacyCodes extends Command
{
    protected $signature = 'ciencuadras:audit-legacy
        {--output= : Ruta del CSV de salida}
        {--concurrency=8 : Consultas simultáneas a Ciencuadras}
        {--all-source : Incluye todos los registros fuente, no solo los publicables}';

    protected $description = 'Audita códigos legados con P en Ciencuadras sin modificar publicaciones.';

    public function handle(CiencuadrasClient $client, Client $http): int
    {
        $credential = $this->credential($client);
        if (! $credential) {
            $this->error('No fue posible iniciar sesión en Ciencuadras.');
            return self::FAILURE;
        }

        $inventoryResult = $client->consultAllProperties($credential);
        if (! ($inventoryResult['ok'] ?? false)) {
            $this->error('No fue posible consultar el inventario de Ciencuadras.');
            return self::FAILURE;
        }

        $inventory = collect($inventoryResult['data']['message'] ?? [])
            ->pluck('propertyCode')
            ->map(fn ($code) => trim((string) $code))
            ->filter();
        $inventoryUnique = $inventory->unique()->values();
        $inventorySet = $inventoryUnique->flip();

        $rows = DB::connection('wordpress')
            ->table('wp_jet_cct_inmuebles')
            ->where('cct_status', 'publish')
            ->whereNotNull('codigo')
            ->get(['codigo', 'estado', 'cct_status', 'tipo_inmueble', 'tipo_negocio']);

        if (! $this->option('all-source')) {
            $rows = $rows->filter(fn ($row) => $this->isPublicStatus($row->estado));
        }

        $rows = $rows->keyBy(fn ($row) => (string) $row->codigo);

        $prefix = (string) config('portals.ciencuadras.property_code_prefix', '22130-');
        $legacyCodes = $rows->keys()
            ->mapWithKeys(fn (string $code) => [$prefix . 'P' . $code => $code]);
        $results = [];
        $apiUrl = rtrim((string) config('portals.ciencuadras.api_url'), '/');
        $concurrency = min(15, max(1, (int) $this->option('concurrency')));

        $requests = function () use ($http, $apiUrl, $credential, $legacyCodes) {
            foreach ($legacyCodes as $portalCode => $sourceCode) {
                yield $portalCode => fn () => $http->postAsync($apiUrl . '/api/consult-property', [
                    'json' => ['propertyCode' => $portalCode],
                    'headers' => [
                        'Authorization' => 'Bearer ' . $credential->access_token,
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ],
                    'timeout' => 45,
                ]);
            }
        };

        $pool = new Pool($http, $requests(), [
            'concurrency' => $concurrency,
            'fulfilled' => function ($response, $portalCode) use (&$results) {
                $results[$portalCode] = [
                    'ok' => true,
                    'data' => json_decode((string) $response->getBody(), true),
                ];
            },
            'rejected' => function ($reason, $portalCode) use (&$results) {
                $response = method_exists($reason, 'getResponse') ? $reason->getResponse() : null;
                $body = $response ? (string) $response->getBody() : null;
                $results[$portalCode] = [
                    'ok' => false,
                    'data' => $body ? (json_decode($body, true) ?: $body) : ['error' => $reason->getMessage()],
                ];
            },
        ]);
        $pool->promise()->wait();

        $audit = collect($legacyCodes)->map(function (string $sourceCode, string $portalCode) use (
            $results,
            $inventorySet,
            $rows,
            $prefix
        ) {
            $result = $results[$portalCode] ?? ['ok' => false, 'data' => ['error' => 'Sin respuesta']];
            $property = $this->propertyData($result);
            $listed = $inventorySet->has($portalCode);
            $cleanCode = $prefix . $sourceCode;
            $cleanListed = $inventorySet->has($cleanCode);
            $exists = $property !== null || $listed;
            $row = $rows->get($sourceCode);
            $active = strtolower((string) ($property['active'] ?? '')) === 'activo'
                || (string) ($property['status'] ?? '') === '0';

            return [
                'codigo_ciencuadras_p' => $portalCode,
                'codigo_interno' => $sourceCode,
                'codigo_limpio' => $cleanCode,
                'p_en_inventario' => $listed ? 'SI' : 'NO',
                'p_existe_consulta' => $exists ? 'SI' : 'NO',
                'p_activo_consulta' => $active ? 'SI' : 'NO',
                'property_id_consultado' => $property['id'] ?? null,
                'estado_api' => $property['active'] ?? null,
                'status_api' => $property['status'] ?? null,
                'fecha_creacion' => data_get($property, 'creationDate.date'),
                'fecha_actualizacion' => data_get($property, 'updateDate.date'),
                'codigo_limpio_en_inventario' => $cleanListed ? 'SI' : 'NO',
                'estado_wordpress' => $row->estado ?? null,
                'cct_status' => $row->cct_status ?? null,
                'tipo_inmueble' => $row->tipo_inmueble ?? null,
                'tipo_negocio' => $row->tipo_negocio ?? null,
                'accion_recomendada' => ! $exists
                    ? 'Sin código P detectable'
                    : ($cleanListed
                        ? 'Eliminar P; conservar limpio'
                        : 'Eliminar P; luego publicar limpio'),
            ];
        })->filter(fn (array $row) => $row['p_existe_consulta'] === 'SI')
            ->sortBy('codigo_interno', SORT_NATURAL)
            ->values();

        $output = $this->option('output')
            ?: base_path('../ciencuadras-codigos-p-' . now()->format('Y-m-d') . '.csv');
        $this->writeCsv($output, $audit->all());

        $this->info('Inventario API: ' . $inventory->count() . ' filas, ' . $inventoryUnique->count() . ' códigos únicos.');
        $this->info('Códigos P detectados: ' . $audit->count() . '.');
        $this->info('P reportados activos por consulta: ' . $audit->where('p_activo_consulta', 'SI')->count() . '.');
        $this->info('P sin equivalente limpio en inventario: ' . $audit->where('codigo_limpio_en_inventario', 'NO')->count() . '.');
        $this->info('Reporte: ' . $output);

        return self::SUCCESS;
    }

    protected function credential(CiencuadrasClient $client): ?PortalCredential
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $result = $client->login();
            $token = ($result['ok'] ?? false)
                ? $client->extractToken($result['data'] ?? [])
                : null;

            if ($token) {
                return new PortalCredential(['access_token' => $token]);
            }
        }

        return null;
    }

    protected function propertyData(array $result): ?array
    {
        $message = $result['data']['message'] ?? null;

        return is_array($message) && array_is_list($message) && isset($message[0]) && is_array($message[0])
            ? $message[0]
            : null;
    }

    protected function isPublicStatus(?string $status): bool
    {
        return in_array(
            Str::ascii(strtolower(trim((string) $status))),
            ['publico', 'publicado'],
            true
        );
    }

    protected function writeCsv(string $path, array $rows): void
    {
        $handle = fopen($path, 'wb');
        if (! $handle) {
            throw new \RuntimeException("No fue posible crear {$path}.");
        }

        fwrite($handle, "\xEF\xBB\xBF");

        $headers = $rows ? array_keys($rows[0]) : [
            'codigo_ciencuadras_p',
            'codigo_interno',
            'codigo_limpio',
        ];
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);
    }
}
