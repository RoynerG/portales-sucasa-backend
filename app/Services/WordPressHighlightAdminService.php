<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use stdClass;

class WordPressHighlightAdminService
{
    public const REPORT_TYPES = ['Promoción premium', 'Visita', 'Otras actividades', 'Retoque'];

    public function history(array $filters = []): array
    {
        $page = max(1, (int) ($filters['pagina'] ?? 1));
        $limit = min(100, max(10, (int) ($filters['limite'] ?? 25)));
        $query = $this->historyQuery();
        $this->applyHistoryFilters($query, $filters);
        $total = (clone $query)->count('d._ID');
        $pages = max(1, (int) ceil($total / $limit));
        $page = min($page, $pages);

        $items = $query->orderByDesc('d.fecha')->orderByDesc('d._ID')->forPage($page, $limit)->get()
            ->map(fn (stdClass $row): array => [
                'id' => (int) $row->_ID,
                'code' => (string) $row->id_inmueble,
                'property_type' => $row->tipo_inmueble,
                'transaction_type' => $row->tipo_negocio,
                'city' => $row->ciudad,
                'neighborhood' => $row->barrio,
                'address' => $row->direccion,
                'property_status' => $row->estado,
                'employee_id' => (string) ($row->id_empleado ?? ''),
                'employee' => $row->empleado,
                'markets' => $this->historyMarkets($row),
                'reason' => $row->observacion_destacado,
                'opportunity' => $row->oportunidad,
                'negotiable' => $row->negociable,
                'highlight_count' => (int) ($row->veces_destacado ?? 0),
                'highlighted_at' => $this->timestamp($row->fecha),
            ])->values()->all();

        $base = $this->historyQuery();
        $allTotal = (clone $base)->count('d._ID');

        return [
            'items' => $items,
            'pagination' => $this->pagination($page, $pages, $limit, $total),
            'summary' => [
                'total' => $allTotal,
                'properties' => (clone $base)->distinct()->count('d.id_inmueble'),
                'employees' => (clone $base)->whereNotNull('d.id_empleado')->distinct()->count('d.id_empleado'),
                'filtered' => $total,
            ],
            'markets' => $this->markets(),
            'employees' => $this->historyEmployees(),
        ];
    }

    public function premium(array $filters = []): array
    {
        $page = max(1, (int) ($filters['pagina'] ?? 1));
        $limit = min(100, max(10, (int) ($filters['limite'] ?? 24)));
        $requestedStatus = (string) ($filters['estado'] ?? 'all');
        $status = in_array($requestedStatus, ['all', 'premium', 'standard'], true) ? $requestedStatus : 'all';
        $base = $this->premiumQuery();
        $query = clone $base;
        if ($status === 'premium') {
            $query->whereIn(DB::raw("LOWER(TRIM(COALESCE(i.promocion_premium, '')))"), ['si', 'sí', '1', 'true']);
        } elseif ($status === 'standard') {
            $query->whereNotIn(DB::raw("LOWER(TRIM(COALESCE(i.promocion_premium, '')))"), ['si', 'sí', '1', 'true']);
        }
        $search = trim((string) ($filters['buscar'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $where) use ($search): void {
                $where->where('i.codigo', 'like', "%{$search}%")
                    ->orWhere('i.direccion', 'like', "%{$search}%")
                    ->orWhere('i.barrio', 'like', "%{$search}%")
                    ->orWhere('i.ciudad', 'like', "%{$search}%")
                    ->orWhere('i.propietario', 'like', "%{$search}%")
                    ->orWhere('p.nombre', 'like', "%{$search}%")
                    ->orWhere('p.nombre_juridico', 'like', "%{$search}%");
            });
        }

        $total = (clone $query)->count('i._ID');
        $pages = max(1, (int) ceil($total / $limit));
        $page = min($page, $pages);
        $rows = $query->orderByDesc('i._ID')->forPage($page, $limit)->get();
        $codes = $rows->pluck('codigo')->filter()->map(fn ($code): string => (string) $code)->all();
        $reportCounts = $this->reportCounts($codes);
        $postMeta = $this->premiumPostMeta($codes);
        $items = $rows->map(function (stdClass $row) use ($reportCounts, $postMeta): array {
            $code = (string) (($row->codigo ?? '') ?: $row->_ID);
            $premium = WordPressHighlightService::isAffirmative($row->promocion_premium ?? null);

            return [
                'id' => (int) $row->_ID,
                'code' => $code,
                'title' => trim(($row->tipo_inmueble ?: 'Inmueble').' en '.($row->tipo_negocio ?: 'gestión').($row->barrio ? ' - '.$row->barrio : '')),
                'property_type' => $row->tipo_inmueble,
                'transaction_type' => $row->tipo_negocio,
                'city' => $row->ciudad,
                'neighborhood' => $row->barrio,
                'address' => $row->direccion,
                'stratum' => (string) ($row->estrato ?? ''),
                'owner' => $row->propietario_nombre,
                'consultant' => $row->funcionario,
                'is_premium' => $premium,
                'premium_synced' => array_key_exists($code, $postMeta) && $premium === WordPressHighlightService::isAffirmative($postMeta[$code]),
                'report_count' => (int) ($reportCounts[$code] ?? 0),
            ];
        })->values()->all();

        $available = (clone $base)->count('i._ID');
        $premiumCount = (clone $base)->whereIn(DB::raw("LOWER(TRIM(COALESCE(i.promocion_premium, '')))"), ['si', 'sí', '1', 'true'])->count('i._ID');

        return [
            'items' => $items,
            'pagination' => $this->pagination($page, $pages, $limit, $total),
            'summary' => ['available' => $available, 'premium' => $premiumCount, 'standard' => max(0, $available - $premiumCount), 'filtered' => $total],
            'report_types' => self::REPORT_TYPES,
        ];
    }

    public function togglePremium(string $code, bool $enabled, User $actor): array
    {
        return DB::connection('wordpress')->transaction(function () use ($code, $enabled, $actor): array {
            $property = $this->findProperty($code, true);
            if (! $property || (string) $property->estado !== 'Publico') {
                throw new DomainException('No se encontró un inmueble público con ese código.');
            }
            if ($enabled && ! in_array(trim((string) $property->estrato), ['4', '5', '6'], true)) {
                throw new DomainException('Solo los inmuebles públicos de estratos 4, 5 y 6 pueden marcarse como Premium.');
            }
            $propertyCode = trim((string) (($property->codigo ?? '') ?: $property->_ID));
            if (! ctype_digit($propertyCode)) {
                throw new DomainException('El inmueble no tiene un código válido para sincronizar WordPress.');
            }
            if (! Schema::connection('wordpress')->hasTable('wp_posts') || ! DB::connection('wordpress')->table('wp_posts')->where('ID', (int) $propertyCode)->where('post_type', 'inmuebles')->exists()) {
                throw new DomainException('No se encontró el post de WordPress asociado al inmueble.');
            }

            $value = $enabled ? 'Si' : 'No';
            DB::connection('wordpress')->table('wp_jet_cct_inmuebles')->where('_ID', $property->_ID)->update(['promocion_premium' => $value]);
            DB::connection('wordpress')->table('wp_postmeta')->updateOrInsert(
                ['post_id' => (int) $propertyCode, 'meta_key' => 'inmueble-premium'],
                ['meta_value' => $value]
            );

            $employeeId = (string) ($actor->legacy_employee_id ?: $actor->id);
            $observation = $enabled ? 'El inmueble fue marcado para promoción premium.' : 'El inmueble fue retirado de la promoción premium.';
            $this->insertPropertyHistory($propertyCode, $actor, 'Promoción premium', $observation, time());

            return [
                'code' => $propertyCode,
                'is_premium' => $enabled,
                'message' => $enabled ? 'Inmueble marcado como Premium y sincronizado con WordPress.' : 'Inmueble retirado de Premium y sincronizado con WordPress.',
                'actor_id' => $employeeId,
            ];
        });
    }

    public function reports(string $code): array
    {
        $property = $this->findProperty($code);
        if (! $property) {
            throw new DomainException('No se encontró el inmueble indicado.');
        }
        $identifier = trim((string) (($property->codigo ?? '') ?: $property->_ID));
        $rows = DB::connection('wordpress')->table('wp_jet_cct_reportes_comerciales')->where('id_inmueble', $identifier)
            ->orderByDesc('cct_created')->orderByDesc('_ID')->limit(100)->get();
        $items = $rows->map(fn (stdClass $row): array => [
            'id' => (int) $row->_ID,
            'date' => $this->timestamp($row->fecha),
            'type' => $row->tipo_reporte,
            'observation' => $row->observacion,
            'employee' => $row->funcionario,
            'created_at' => $this->timestamp($row->cct_created),
        ])->values()->all();
        $recentCutoff = now()->subDays(30);

        return [
            'code' => $identifier,
            'is_premium' => WordPressHighlightService::isAffirmative($property->promocion_premium ?? null),
            'items' => $items,
            'metrics' => [
                'activities' => count($items),
                'visits' => collect($items)->filter(fn (array $item): bool => mb_strtolower(trim((string) $item['type'])) === 'visita')->count(),
                'recent' => collect($items)->filter(fn (array $item): bool => $item['created_at'] && Carbon::parse($item['created_at'])->greaterThanOrEqualTo($recentCutoff))->count(),
                'last_activity' => $items[0]['created_at'] ?? null,
                'leads' => $this->leadCount($identifier),
            ],
            'report_types' => self::REPORT_TYPES,
        ];
    }

    public function addReport(string $code, string $type, string $observation, string $date, User $actor): array
    {
        if (! in_array($type, self::REPORT_TYPES, true)) {
            throw new DomainException('Selecciona un tipo de reporte válido.');
        }
        $length = mb_strlen($observation);
        if ($length < 10 || $length > 3000) {
            throw new DomainException('La observación debe tener entre 10 y 3.000 caracteres.');
        }
        try {
            $reportDate = Carbon::createFromFormat('Y-m-d', $date)->startOfDay()->addHours(12);
        } catch (\Throwable) {
            throw new DomainException('Selecciona una fecha válida.');
        }
        if ($reportDate->format('Y-m-d') !== $date) {
            throw new DomainException('Selecciona una fecha válida.');
        }

        return DB::connection('wordpress')->transaction(function () use ($code, $type, $observation, $reportDate, $actor): array {
            $property = $this->findProperty($code, true);
            if (! $property || (string) $property->estado !== 'Publico' || ! WordPressHighlightService::isAffirmative($property->promocion_premium ?? null)) {
                throw new DomainException('Solo puedes registrar reportes para un inmueble Premium público.');
            }
            $identifier = trim((string) (($property->codigo ?? '') ?: $property->_ID));
            $employeeId = (string) ($actor->legacy_employee_id ?: $actor->id);
            $employeeName = $actor->name ?: 'Equipo comercial';
            $timestamp = $reportDate->timestamp;
            $reportId = DB::connection('wordpress')->table('wp_jet_cct_reportes_comerciales')->insertGetId([
                'cct_status' => 'publish',
                'id_inmueble' => $identifier,
                'fecha' => $timestamp,
                'tipo_reporte' => $type,
                'observacion' => $observation,
                'valor' => 0,
                'funcionario' => $employeeName,
                'id_empleado' => $employeeId,
                'cct_author_id' => max(1, (int) $employeeId),
                'cct_created' => now(),
            ]);
            $this->insertPropertyHistory($identifier, $actor, $type, $observation, $timestamp);

            return ['id' => $reportId, 'code' => $identifier, 'message' => 'Reporte Premium registrado y agregado al historial del inmueble.'];
        });
    }

    private function historyQuery(): Builder
    {
        $query = DB::connection('wordpress')->table('wp_jet_cct_inmuebles_destacados as d')
            ->leftJoin('wp_jet_cct_inmuebles as i', 'i.codigo', '=', 'd.id_inmueble');
        $query->where(function (Builder $where): void {
            foreach (WordPressHighlightService::MARKETS as $market) {
                $where->orWhere('d.'.$market['history_column'], 'Si');
            }
        });

        return $query->select(array_merge([
            'd._ID', 'd.id_inmueble', 'd.fecha', 'd.id_empleado', 'd.empleado', 'd.observacion_destacado', 'd.veces_destacado', 'd.oportunidad', 'd.negociable',
            'i.tipo_inmueble', 'i.tipo_negocio', 'i.ciudad', 'i.barrio', 'i.direccion', 'i.estado',
        ], collect(WordPressHighlightService::MARKETS)->map(fn (array $market): string => 'd.'.$market['history_column'])->all()));
    }

    private function applyHistoryFilters(Builder $query, array $filters): void
    {
        $search = trim((string) ($filters['buscar'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $where) use ($search): void {
                $where->where('d.id_inmueble', 'like', "%{$search}%")->orWhere('d.empleado', 'like', "%{$search}%")
                    ->orWhere('i.tipo_inmueble', 'like', "%{$search}%")->orWhere('i.ciudad', 'like', "%{$search}%")->orWhere('i.barrio', 'like', "%{$search}%");
            });
        }
        $market = trim((string) ($filters['mercado'] ?? ''));
        if ($market !== '' && isset(WordPressHighlightService::MARKETS[$market])) {
            $query->where('d.'.WordPressHighlightService::MARKETS[$market]['history_column'], 'Si');
        }
        if (($employee = trim((string) ($filters['funcionario_id'] ?? ''))) !== '') {
            $query->where('d.id_empleado', $employee);
        }
        if (($from = trim((string) ($filters['desde'] ?? ''))) !== '') {
            $query->where('d.fecha', '>=', Carbon::parse($from)->startOfDay()->timestamp);
        }
        if (($to = trim((string) ($filters['hasta'] ?? ''))) !== '') {
            $query->where('d.fecha', '<=', Carbon::parse($to)->endOfDay()->timestamp);
        }
    }

    private function premiumQuery(): Builder
    {
        return DB::connection('wordpress')->table('wp_jet_cct_inmuebles as i')
            ->leftJoin('wp_jet_cct_propietarios as p', 'p.id_propietario', '=', 'i.id_propietario')
            ->where('i.cct_status', 'publish')->where('i.estado', 'Publico')->whereIn(DB::raw('TRIM(CAST(i.estrato AS CHAR))'), ['4', '5', '6'])
            ->select(['i._ID', 'i.codigo', 'i.tipo_inmueble', 'i.tipo_negocio', 'i.ciudad', 'i.barrio', 'i.direccion', 'i.estrato', 'i.promocion_premium', 'i.funcionario'])
            ->selectRaw("COALESCE(NULLIF(i.propietario, ''), NULLIF(p.nombre, ''), NULLIF(p.nombre_juridico, '')) AS propietario_nombre");
    }

    private function findProperty(string $code, bool $lock = false): ?stdClass
    {
        $query = DB::connection('wordpress')->table('wp_jet_cct_inmuebles')->where(function (Builder $where) use ($code): void {
            $where->where('codigo', $code);
            if (ctype_digit($code)) {
                $where->orWhere('_ID', (int) $code);
            }
        });
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function insertPropertyHistory(string $code, User $actor, string $type, string $observation, int $timestamp): void
    {
        $employeeId = (string) ($actor->legacy_employee_id ?: $actor->id);
        DB::connection('wordpress')->table('wp_jet_cct_historial_del_inmueble')->insert([
            'cct_status' => 'publish', 'cct_author_id' => max(1, (int) $employeeId), 'cct_created' => now(), 'cct_modified' => now(),
            'id_empleado' => $employeeId, 'id_inmueble' => $code, 'fecha' => $timestamp, 'tipo_reporte' => $type,
            'observacion' => $observation, 'funcionario' => $actor->name ?: 'Equipo comercial',
        ]);
    }

    private function reportCounts(array $codes): array
    {
        if ($codes === [] || ! Schema::connection('wordpress')->hasTable('wp_jet_cct_reportes_comerciales')) return [];
        return DB::connection('wordpress')->table('wp_jet_cct_reportes_comerciales')->whereIn('id_inmueble', $codes)
            ->selectRaw('id_inmueble, COUNT(*) AS aggregate')->groupBy('id_inmueble')->pluck('aggregate', 'id_inmueble')->map(fn ($count): int => (int) $count)->all();
    }

    private function premiumPostMeta(array $codes): array
    {
        if ($codes === [] || ! Schema::connection('wordpress')->hasTable('wp_postmeta')) return [];
        return DB::connection('wordpress')->table('wp_postmeta')->where('meta_key', 'inmueble-premium')->whereIn('post_id', array_map('intval', $codes))
            ->orderBy('meta_id')->get(['post_id', 'meta_value'])->mapWithKeys(fn (stdClass $row): array => [(string) $row->post_id => $row->meta_value])->all();
    }

    private function leadCount(string $identifier): int
    {
        if (! Schema::connection('wordpress')->hasTable('wp_jet_cct_cotizacion_inmuebles')) return 0;
        return DB::connection('wordpress')->table('wp_jet_cct_cotizacion_inmuebles')->where('inmuebles', 'like', '%s:'.strlen($identifier).':"'.$identifier.'";%')->count();
    }

    private function historyEmployees(): array
    {
        return $this->historyQuery()->whereNotNull('d.id_empleado')->where('d.id_empleado', '<>', '')
            ->select(['d.id_empleado as id', 'd.empleado as name'])->distinct()->orderBy('d.empleado')->limit(500)->get()->map(fn (stdClass $row): array => ['id' => (string) $row->id, 'name' => (string) $row->name])->all();
    }

    private function markets(): array
    {
        return collect(WordPressHighlightService::MARKETS)->map(fn (array $market, string $key): array => ['key' => $key, 'label' => $market['label']])->values()->all();
    }

    private function historyMarkets(stdClass $row): array
    {
        return collect(WordPressHighlightService::MARKETS)
            ->filter(fn (array $market): bool => WordPressHighlightService::isAffirmative($row->{$market['history_column']} ?? null))
            ->map(fn (array $market, string $key): array => ['key' => $key, 'label' => $market['label']])
            ->values()
            ->all();
    }

    private function pagination(int $page, int $pages, int $limit, int $total): array
    {
        return ['page' => $page, 'pages' => $pages, 'limit' => $limit, 'total' => $total, 'from' => $total > 0 ? (($page - 1) * $limit) + 1 : 0, 'to' => min($total, $page * $limit)];
    }

    private function timestamp(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') return null;
        try { return is_numeric($value) ? Carbon::createFromTimestamp((int) $value)->toIso8601String() : Carbon::parse((string) $value)->toIso8601String(); } catch (\Throwable) { return null; }
    }
}
