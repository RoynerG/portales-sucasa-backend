<?php

namespace App\Console\Commands;

use App\Models\PortalCredential;
use App\Services\Portals\CiencuadrasClient;
use App\Services\Portals\CiencuadrasPropertyMapper;
use Illuminate\Console\Command;

class DeleteCiencuadrasLegacyCodes extends Command
{
    protected $signature = 'ciencuadras:delete-legacy
        {--input= : CSV generado por ciencuadras:audit-legacy}
        {--output= : CSV de resultados}
        {--batch=20 : Cantidad de inmuebles por petición}
        {--apply : Envía las eliminaciones a Ciencuadras}';

    protected $description = 'Elimina por lotes códigos legados con P y registra las solicitudes enviadas.';

    public function handle(CiencuadrasClient $client, CiencuadrasPropertyMapper $mapper): int
    {
        $input = (string) ($this->option('input')
            ?: base_path('../ciencuadras-todos-codigos-p-verificados-'.now()->format('Y-m-d').'.csv'));
        $rows = $this->readCsv($input);
        $prefix = (string) config('portals.ciencuadras.property_code_prefix', '22130-');
        $targets = collect($rows)
            ->map(function (array $row) use ($prefix) {
                $portalCode = trim((string) ($row['codigo_ciencuadras_p'] ?? ''));
                $sourceCode = trim((string) ($row['codigo_interno'] ?? ''));

                return [
                    'portal_code' => $portalCode,
                    'source_code' => $sourceCode,
                    'valid' => preg_match('/^'.preg_quote($prefix, '/').'P\d+$/i', $portalCode) === 1
                        && $sourceCode !== '',
                ];
            })
            ->filter(fn (array $row) => $row['valid'])
            ->unique('portal_code')
            ->values();

        if ($targets->isEmpty()) {
            $this->error('El archivo no contiene códigos P válidos.');

            return self::FAILURE;
        }

        $this->info("Objetivos validados: {$targets->count()} códigos P.");

        if (! $this->option('apply')) {
            $this->warn('Simulación: no se enviaron cambios. Usa --apply para ejecutar.');

            return self::SUCCESS;
        }

        $credential = $this->credential($client);
        if (! $credential) {
            $this->error('No fue posible iniciar sesión en Ciencuadras.');

            return self::FAILURE;
        }

        $batchSize = min(50, max(1, (int) $this->option('batch')));
        $results = [];
        $sent = 0;
        $failed = 0;

        foreach ($targets->chunk($batchSize) as $batchIndex => $batch) {
            $payloads = [];
            $prepared = [];

            foreach ($batch as $target) {
                try {
                    $mapped = $mapper->fromCode($target['source_code'], 'D');
                    $payload = $mapped['payload'];
                    $payload['propertyCode'] = $target['portal_code'];
                    $payload['status'] = 'D';
                    $payloads[] = $payload;
                    $prepared[] = $target;
                } catch (\Throwable $exception) {
                    $failed++;
                    $results[] = $this->resultRow(
                        $target,
                        $batchIndex + 1,
                        false,
                        null,
                        null,
                        $exception->getMessage()
                    );
                }
            }

            if ($payloads === []) {
                continue;
            }

            try {
                $response = $client->updateProperty($payloads, $credential);
                $idRequest = ($response['ok'] ?? false)
                    ? $client->extractIdRequest($response['data'] ?? [])
                    : null;
                $ok = (bool) ($response['ok'] ?? false);
                $error = $ok ? null : $this->shortJson($response['data'] ?? []);

                foreach ($prepared as $target) {
                    $results[] = $this->resultRow(
                        $target,
                        $batchIndex + 1,
                        $ok,
                        $idRequest,
                        $response['data'] ?? null,
                        $error
                    );
                    $ok ? $sent++ : $failed++;
                }

                $state = $ok ? 'enviado' : 'error';
                $this->line(
                    'Lote '.($batchIndex + 1).": {$state}; "
                    .count($prepared).' códigos; idRequest='.($idRequest ?: 'sin idRequest')
                );
            } catch (\Throwable $exception) {
                foreach ($prepared as $target) {
                    $failed++;
                    $results[] = $this->resultRow(
                        $target,
                        $batchIndex + 1,
                        false,
                        null,
                        null,
                        $exception->getMessage()
                    );
                }

                $this->error('Lote '.($batchIndex + 1).': '.$exception->getMessage());
            }
        }

        $output = (string) ($this->option('output')
            ?: base_path('../ciencuadras-eliminacion-p-'.now()->format('Y-m-d-His').'.csv'));
        $this->writeCsv($output, $results);

        $this->info("Enviados: {$sent} | Fallidos: {$failed}");
        $this->info("Resultado: {$output}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
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

    protected function resultRow(
        array $target,
        int $batch,
        bool $sent,
        ?string $idRequest,
        mixed $status,
        ?string $error
    ): array {
        return [
            'codigo_ciencuadras_p' => $target['portal_code'],
            'codigo_interno' => $target['source_code'],
            'lote' => $batch,
            'enviado' => $sent ? 'SI' : 'NO',
            'id_request' => $idRequest,
            'estado_solicitud' => $this->shortJson($status),
            'error' => $error,
            'fecha_envio' => now()->toISOString(),
        ];
    }

    protected function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (! $handle) {
            throw new \RuntimeException("No fue posible abrir {$path}.");
        }

        $headers = fgetcsv($handle);
        if (! $headers) {
            fclose($handle);

            return [];
        }
        $headers[0] = ltrim((string) $headers[0], "\xEF\xBB\xBF");

        $rows = [];
        while (($values = fgetcsv($handle)) !== false) {
            if (count($values) !== count($headers)) {
                continue;
            }
            $rows[] = array_combine($headers, $values);
        }

        fclose($handle);

        return $rows;
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
            'lote',
            'enviado',
            'id_request',
            'estado_solicitud',
            'error',
            'fecha_envio',
        ];
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);
    }

    protected function shortJson(mixed $value): string
    {
        return substr(
            json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            0,
            1500
        );
    }
}
