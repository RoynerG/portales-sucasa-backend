<?php

namespace App\Console\Commands;

use App\Models\PortalCredential;
use App\Services\Portals\CiencuadrasClient;
use App\Services\Portals\CiencuadrasPropertyMapper;
use Illuminate\Console\Command;

class RepairCiencuadrasCodes extends Command
{
    protected $signature = 'ciencuadras:repair-codes
        {--code=* : Codigo interno del inmueble a revisar}
        {--apply : Envia update limpio a Ciencuadras}
        {--replace-old : Con --apply, despublica únicamente el código viejo con P}';

    protected $description = 'Audita y corrige codigos Ciencuadras guardados con P antes del codigo interno.';

    public function handle(CiencuadrasClient $client, CiencuadrasPropertyMapper $mapper): int
    {
        $codes = collect($this->option('code'))
            ->map(fn ($code) => trim((string) $code))
            ->filter()
            ->unique()
            ->values();

        if ($codes->isEmpty()) {
            $this->error('Indica al menos un codigo: --code=101247');

            return self::FAILURE;
        }

        $credential = $this->credential($client);
        if (! $credential) {
            $this->error('No fue posible iniciar sesion en Ciencuadras.');

            return self::FAILURE;
        }

        foreach ($codes as $code) {
            $cleanFullCode = $mapper->lookupCode($code);
            $oldFullCode = $this->oldFullCode($cleanFullCode);
            $oldResult = $client->consultProperty($oldFullCode, $credential);
            $cleanResult = $client->consultProperty($cleanFullCode, $credential);

            $this->line('');
            $this->info("{$code}");
            $this->line("  Viejo con P: {$oldFullCode} => ".($this->exists($oldResult) ? 'EXISTE' : 'no existe'));
            $this->line("  Limpio: {$cleanFullCode} => ".($this->exists($cleanResult) ? 'EXISTE' : 'no existe'));

            if (! $this->option('apply')) {
                continue;
            }

            if ($this->option('replace-old')) {
                $this->replaceOldCode($client, $mapper, $credential, $code, $oldFullCode);

                continue;
            }

            $mapped = $mapper->fromCode($code, 'A');
            if ($mapped['errors']) {
                $this->error('  No se envia update: '.implode(' ', $mapped['errors']));

                continue;
            }

            $update = $client->updateProperty($mapped['payload'], $credential);
            $idRequest = $client->extractIdRequest($update['data'] ?? []);
            $status = $idRequest ? $client->consultStatus(['idRequest' => $idRequest], $credential) : null;
            $afterClean = $client->consultProperty($cleanFullCode, $credential);
            $afterOld = $client->consultProperty($oldFullCode, $credential);

            $this->line('  Update limpio enviado: '.(($update['ok'] ?? false) ? 'OK' : 'ERROR'));
            $this->line('  idRequest: '.($idRequest ?: 'sin idRequest'));
            if ($status) {
                $this->line('  Estado solicitud: '.$this->shortJson($status['data'] ?? []));
            }
            $this->line("  Despues limpio: {$cleanFullCode} => ".($this->exists($afterClean) ? 'EXISTE' : 'no existe'));
            $this->line("  Despues viejo: {$oldFullCode} => ".($this->exists($afterOld) ? 'EXISTE' : 'no existe'));
        }

        return self::SUCCESS;
    }

    protected function replaceOldCode(
        CiencuadrasClient $client,
        CiencuadrasPropertyMapper $mapper,
        PortalCredential $credential,
        string $code,
        string $oldFullCode
    ): void {
        $oldResult = $client->consultProperty($oldFullCode, $credential);
        if (! $this->exists($oldResult)) {
            $this->line("  No se envía despublicación: {$oldFullCode} no existe.");

            return;
        }

        if ($this->isInactive($oldResult)) {
            $this->line("  El código viejo {$oldFullCode} ya está inactivo. No se vuelve a enviar.");

            return;
        }

        $oldMapped = $mapper->fromCode($code, 'D');
        if ($oldMapped['errors']) {
            $this->error('  No se despublica viejo: '.implode(' ', $oldMapped['errors']));

            return;
        }

        $oldMapped['payload']['propertyCode'] = $oldFullCode;
        $delete = $client->updateProperty($oldMapped['payload'], $credential);
        $deleteId = $client->extractIdRequest($delete['data'] ?? []);
        $deleteStatus = $deleteId ? $client->consultStatus(['idRequest' => $deleteId], $credential) : null;

        $this->line('  Despublicacion viejo enviada: '.(($delete['ok'] ?? false) ? 'OK' : 'ERROR'));
        $this->line('  Viejo idRequest: '.($deleteId ?: 'sin idRequest'));
        if ($deleteStatus) {
            $this->line('  Estado viejo: '.$this->shortJson($deleteStatus['data'] ?? []));
        }
        $this->line('  La publicación limpia queda bloqueada hasta que Ciencuadras deje de reportar el código viejo.');
    }

    protected function credential(CiencuadrasClient $client): ?PortalCredential
    {
        $result = $client->login([
            'username' => config('portals.ciencuadras.username'),
            'password' => config('portals.ciencuadras.password'),
        ]);

        $token = $result['ok'] ? $client->extractToken($result['data'] ?? []) : null;

        return $token ? new PortalCredential(['access_token' => $token]) : null;
    }

    protected function oldFullCode(string $cleanFullCode): string
    {
        return preg_replace('/^(\d+-)(\d+)$/', '$1P$2', $cleanFullCode) ?: $cleanFullCode;
    }

    protected function exists(array $result): bool
    {
        $json = strtolower(json_encode($result['data'] ?? []));

        return ($result['ok'] ?? false)
            && ! str_contains($json, 'no existe')
            && ! str_contains($json, 'not found')
            && ! str_contains($json, '"status":"error"');
    }

    protected function isInactive(array $result): bool
    {
        $json = strtolower(json_encode($result['data'] ?? []));

        return str_contains($json, '"active":"inactivo"')
            || str_contains($json, '"active":"eliminado"')
            || str_contains($json, '"status":"5"')
            || str_contains($json, '"status":"8"');
    }

    protected function shortJson($data): string
    {
        return substr(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 600);
    }
}
