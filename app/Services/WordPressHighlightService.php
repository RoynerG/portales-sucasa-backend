<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use OutOfBoundsException;
use stdClass;

class WordPressHighlightService
{
    private const QUOTA_CARGO_IDS = ['13', '14', '12', '11', '1', '6', '9', '10', '17'];

    public const MARKETS = [
        'mercado_libre_destacados' => [
            'label' => 'Mercado Libre',
            'property_column' => 'mercado_libre_destacado',
            'history_column' => 'mercado_libre_destacados',
        ],
        'proppit_promocionados' => [
            'label' => 'Proppit',
            'property_column' => 'proppit_promocionado',
            'history_column' => 'proppit_promocionados',
        ],
        'ciencuadras_ascendidos' => [
            'label' => 'Ciencuadras Ascendido',
            'property_column' => 'ciencuadras_ascendido',
            'history_column' => 'ciencuadras_ascendidos',
        ],
        'ciencuadras_destacados' => [
            'label' => 'Ciencuadras Destacado',
            'property_column' => 'ciencuadras_destacado',
            'history_column' => 'ciencuadras_destacados',
        ],
        'finca_raiz_silver' => [
            'label' => 'Finca Raíz Silver',
            'property_column' => 'finca_raiz_silver',
            'history_column' => 'finca_raiz_silver',
        ],
        'finca_raiz_gold' => [
            'label' => 'Finca Raíz Gold',
            'property_column' => 'finca_raiz_gold',
            'history_column' => 'finca_raiz_gold',
        ],
        'finca_raiz_black' => [
            'label' => 'Finca Raíz Black',
            'property_column' => 'finca_raiz_black',
            'history_column' => 'finca_raiz_black',
        ],
    ];

    public function index(array $filters = []): array
    {
        $page = max(1, (int) ($filters['pagina'] ?? 1));
        $limit = min(100, max(10, (int) ($filters['limite'] ?? 25)));
        $query = $this->listingQuery();
        $this->applyFilters($query, $filters);

        $total = (clone $query)->count('i._ID');
        $pages = max(1, (int) ceil($total / $limit));
        $page = min($page, $pages);

        $items = $query
            ->orderByDesc('i.fecha_destacado')
            ->orderByDesc('i._ID')
            ->forPage($page, $limit)
            ->get()
            ->map(fn (stdClass $row): array => $this->mapRow($row))
            ->values()
            ->all();

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'pages' => $pages,
                'limit' => $limit,
                'total' => $total,
                'from' => $total > 0 ? (($page - 1) * $limit) + 1 : 0,
                'to' => min($total, $page * $limit),
            ],
            'summary' => $this->summary(),
            'markets' => $this->marketOptions(),
            'consultants' => $this->consultants(),
        ];
    }

    public function release(string $code, User $actor): array
    {
        return DB::connection('wordpress')->transaction(function () use ($code, $actor): array {
            $property = DB::connection('wordpress')
                ->table('wp_jet_cct_inmuebles')
                ->where(function (Builder $query) use ($code): void {
                    $query->where('codigo', $code);
                    if (ctype_digit($code)) {
                        $query->orWhere('_ID', (int) $code);
                    }
                })
                ->lockForUpdate()
                ->first();

            if (! $property) {
                throw new OutOfBoundsException('No se encontró el inmueble indicado.');
            }

            $markets = self::marketsFor($property);
            if (! $markets && ! $this->isYes($property->destacado ?? null)) {
                throw new OutOfBoundsException('El inmueble ya no tiene un destacado activo.');
            }

            $updates = [
                'destacado' => 'No',
                'marcado_destacado' => 'No',
                'fecha_destacado' => null,
            ];
            foreach (self::MARKETS as $market) {
                $updates[$market['property_column']] = 'No';
            }

            DB::connection('wordpress')
                ->table('wp_jet_cct_inmuebles')
                ->where('_ID', $property->_ID)
                ->update($updates);

            $employeeId = (string) ($actor->legacy_employee_id ?: $actor->id);
            DB::connection('wordpress')->table('wp_jet_cct_historial_del_inmueble')->insert([
                'fecha' => time(),
                'id_inmueble' => (string) (($property->codigo ?? '') ?: $property->_ID),
                'id_empleado' => $employeeId,
                'funcionario' => $actor->name ?: 'Funcionario',
                'tipo_reporte' => 'Destacado',
                'observacion' => 'Se liberó el cupo de destacado desde el panel de portales. Mercados desactivados: '.collect($markets)->pluck('label')->join(', ').'. No se eliminó el historial ni se ejecutaron acciones en los portales externos.',
                'cct_author_id' => (int) $employeeId,
                'cct_created' => now(),
                'cct_modified' => now(),
            ]);

            return [
                'code' => (string) (($property->codigo ?? '') ?: $property->_ID),
                'released_markets' => $markets,
                'message' => 'Cupo liberado correctamente. El historial se conservó.',
            ];
        });
    }

    public function quotas(array $filters = []): array
    {
        $page = max(1, (int) ($filters['pagina'] ?? 1));
        $limit = min(100, max(10, (int) ($filters['limite'] ?? 25)));
        $query = $this->quotaEmployeesQuery();
        $search = trim((string) ($filters['buscar'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $where) use ($search): void {
                $where->where('f.nombre', 'like', "%{$search}%")
                    ->orWhere('f.id_empleado', 'like', "%{$search}%")
                    ->orWhere('f.rol', 'like', "%{$search}%")
                    ->orWhere('f.gestion', 'like', "%{$search}%");
            });
        }

        $total = (clone $query)->count('f._ID');
        $pages = max(1, (int) ceil($total / $limit));
        $page = min($page, $pages);
        $employees = $query
            ->orderByRaw("CASE WHEN f.rol = 'Servicio al cliente' THEN 0 ELSE 1 END")
            ->orderBy('f.nombre')
            ->forPage($page, $limit)
            ->get();
        $usage = $this->quotaUsage($employees->pluck('id_empleado')->map(fn ($id): string => (string) $id)->all());

        return [
            'items' => $employees->map(fn (stdClass $employee): array => $this->mapQuotaEmployee($employee, $usage))->values()->all(),
            'pagination' => [
                'page' => $page,
                'pages' => $pages,
                'limit' => $limit,
                'total' => $total,
                'from' => $total > 0 ? (($page - 1) * $limit) + 1 : 0,
                'to' => min($total, $page * $limit),
            ],
            'summary' => $this->quotaSummary(),
            'markets' => $this->marketOptions(),
        ];
    }

    public function updateQuotas(string $employeeRecordId, array $values): array
    {
        return DB::connection('wordpress')->transaction(function () use ($employeeRecordId, $values): array {
            $employee = $this->quotaEmployeesQuery()
                ->where('f._ID', $employeeRecordId)
                ->lockForUpdate()
                ->first();
            if (! $employee) {
                throw new DomainException('No se encontró el funcionario activo indicado.');
            }

            $limits = $this->quotaLimits();
            $assigned = $this->quotaAssignedTotals();
            $updates = [];
            foreach (self::MARKETS as $key => $market) {
                $value = array_key_exists($key, $values) ? (int) $values[$key] : (int) ($employee->{$key} ?? 0);
                if ($value < 0) {
                    throw new DomainException('Los cupos no pueden ser negativos.');
                }

                $projected = (int) ($assigned[$key] ?? 0) - (int) ($employee->{$key} ?? 0) + $value;
                if ($projected > (int) ($limits[$key] ?? 0)) {
                    throw new DomainException("{$market['label']} supera el límite general: {$projected}/{$limits[$key]} cupos asignados.");
                }
                $updates[$key] = $value;
            }

            DB::connection('wordpress')->table('wp_jet_cct_funcionarios')->where('_ID', $employee->_ID)->update($updates);

            return [
                'employee_id' => (string) $employee->_ID,
                'employee_name' => (string) $employee->nombre,
                'quotas' => $updates,
                'message' => 'Cupos de '.($employee->nombre ?: 'funcionario').' actualizados correctamente.',
            ];
        });
    }

    public function updateQuotaLimits(array $values): array
    {
        return DB::connection('wordpress')->transaction(function () use ($values): array {
            $current = $this->quotaLimits();
            $assigned = $this->quotaAssignedTotals();
            $updates = [];
            foreach (self::MARKETS as $key => $market) {
                $value = array_key_exists($key, $values) ? (int) $values[$key] : (int) ($current[$key] ?? 0);
                if ($value < 0) {
                    throw new DomainException('Los límites generales no pueden ser negativos.');
                }
                if ($value < (int) ($assigned[$key] ?? 0)) {
                    throw new DomainException("{$market['label']} no puede quedar en {$value}: ya hay {$assigned[$key]} cupos asignados a funcionarios.");
                }
                $updates[$key] = $value;
            }

            foreach ($updates as $key => $value) {
                DB::connection('wordpress')->table('wp_jet_cct_confi_sistema')->updateOrInsert(
                    ['funcion' => $key],
                    ['valor' => (string) $value]
                );
            }

            return [
                'limits' => $updates,
                'message' => 'Límites generales de cupos actualizados en la configuración del sistema.',
            ];
        });
    }

    public static function marketsFor(object|array $property): array
    {
        return collect(self::MARKETS)
            ->filter(function (array $market) use ($property): bool {
                $value = is_array($property)
                    ? ($property[$market['property_column']] ?? null)
                    : ($property->{$market['property_column']} ?? null);

                return self::isAffirmative($value);
            })
            ->map(fn (array $market, string $key): array => [
                'key' => $key,
                'label' => $market['label'],
            ])
            ->values()
            ->all();
    }

    public static function isAffirmative(mixed $value): bool
    {
        return in_array(mb_strtolower(trim((string) $value)), ['1', 'si', 'sí', 'yes', 'true', 'activo', 'activa', 'destacado', 'promocionado'], true);
    }

    private function listingQuery(): Builder
    {
        $latestHighlight = DB::connection('wordpress')
            ->table('wp_jet_cct_inmuebles_destacados')
            ->selectRaw('id_inmueble, MAX(_ID) AS latest_id')
            ->groupBy('id_inmueble');

        $latestRequest = DB::connection('wordpress')
            ->table('wp_skc_destacado_solicitudes')
            ->where('estado', 'destacado')
            ->selectRaw('codigo_inmueble, MAX(id) AS latest_id')
            ->groupBy('codigo_inmueble');

        $query = $this->activePropertiesQuery()
            ->leftJoinSub($latestHighlight, 'latest_highlight', 'latest_highlight.id_inmueble', '=', 'i.codigo')
            ->leftJoin('wp_jet_cct_inmuebles_destacados as d', 'd._ID', '=', 'latest_highlight.latest_id')
            ->leftJoinSub($latestRequest, 'latest_request', 'latest_request.codigo_inmueble', '=', 'i.codigo')
            ->leftJoin('wp_skc_destacado_solicitudes as s', 's.id', '=', 'latest_request.latest_id');

        return $query->select([
            'i._ID as id',
            'i.codigo as code',
            'i.tipo_inmueble as property_type',
            'i.tipo_negocio as transaction_type',
            'i.ciudad as city',
            'i.barrio as neighborhood',
            'i.direccion as address',
            'i.id_funcionario as consultant_id',
            'i.funcionario as consultant',
            'i.fecha_destacado as property_highlighted_at',
            'i.destacado',
            'i.marcado_destacado',
            'i.mercado_libre_destacado',
            'i.proppit_promocionado',
            'i.ciencuadras_ascendido',
            'i.ciencuadras_destacado',
            'i.finca_raiz_silver',
            'i.finca_raiz_gold',
            'i.finca_raiz_black',
            'd.fecha as history_highlighted_at',
            'd.id_empleado as highlighted_by_id',
            'd.empleado as highlighted_by',
            'd.observacion_destacado as reason',
            'd.veces_destacado as highlight_count',
            'd.oportunidad as opportunity',
            'd.negociable as negotiable',
            's.completado_por_id as completed_by_id',
            's.completado_por_nombre as completed_by',
            's.requested_at',
            's.completed_at',
        ]);
    }

    private function activePropertiesQuery(): Builder
    {
        return DB::connection('wordpress')
            ->table('wp_jet_cct_inmuebles as i')
            ->where('i.cct_status', 'publish')
            ->where('i.estado', 'Publico')
            ->where(function (Builder $query): void {
                $query->where('i.destacado', 'Si');
                foreach (self::MARKETS as $market) {
                    $query->orWhere('i.'.$market['property_column'], 'Si');
                }
            });
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        $search = trim((string) ($filters['buscar'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery->where('i.codigo', 'like', "%{$search}%")
                    ->orWhere('i.tipo_inmueble', 'like', "%{$search}%")
                    ->orWhere('i.direccion', 'like', "%{$search}%")
                    ->orWhere('i.barrio', 'like', "%{$search}%")
                    ->orWhere('i.ciudad', 'like', "%{$search}%")
                    ->orWhere('i.funcionario', 'like', "%{$search}%")
                    ->orWhere('d.empleado', 'like', "%{$search}%");
            });
        }

        $market = trim((string) ($filters['mercado'] ?? ''));
        if ($market !== '' && isset(self::MARKETS[$market])) {
            $query->where('i.'.self::MARKETS[$market]['property_column'], 'Si');
        }

        $consultant = trim((string) ($filters['funcionario_id'] ?? ''));
        if ($consultant !== '') {
            $query->where('i.id_funcionario', $consultant);
        }
    }

    private function summary(): array
    {
        $active = $this->activePropertiesQuery();
        $markets = [];
        foreach (self::MARKETS as $key => $market) {
            $markets[$key] = (clone $active)->where('i.'.$market['property_column'], 'Si')->count();
        }

        return [
            'active' => (clone $active)->count(),
            'consultants' => (clone $active)->whereNotNull('i.id_funcionario')->distinct()->count('i.id_funcionario'),
            'pending' => $this->pendingCount(),
            'history' => DB::connection('wordpress')->table('wp_jet_cct_inmuebles_destacados')->count(),
            'markets' => $markets,
        ];
    }

    private function pendingCount(): int
    {
        return DB::connection('wordpress')
            ->table('wp_skc_destacado_solicitudes as s')
            ->leftJoin('wp_jet_cct_inmuebles as i', 'i.codigo', '=', 's.codigo_inmueble')
            ->where('s.estado', 'pendiente')
            ->where(function (Builder $query): void {
                foreach (self::MARKETS as $key => $market) {
                    $query->orWhere(function (Builder $portal) use ($key, $market): void {
                        $portal->where('s.portal', $key)
                            ->where(function (Builder $inactive) use ($market): void {
                                $inactive->whereNull('i.'.$market['property_column'])
                                    ->orWhere('i.'.$market['property_column'], '<>', 'Si');
                            });
                    });
                }
            })
            ->count();
    }

    private function consultants(): array
    {
        return $this->activePropertiesQuery()
            ->whereNotNull('i.id_funcionario')
            ->where('i.id_funcionario', '<>', '')
            ->select(['i.id_funcionario as id', 'i.funcionario as name'])
            ->distinct()
            ->orderBy('i.funcionario')
            ->limit(500)
            ->get()
            ->map(fn (stdClass $row): array => ['id' => (string) $row->id, 'name' => (string) $row->name])
            ->all();
    }

    private function marketOptions(): array
    {
        return collect(self::MARKETS)
            ->map(fn (array $market, string $key): array => ['key' => $key, 'label' => $market['label']])
            ->values()
            ->all();
    }

    private function quotaEmployeesQuery(): Builder
    {
        return DB::connection('wordpress')
            ->table('wp_jet_cct_funcionarios as f')
            ->where('f.activo', 'Si')
            ->whereIn('f.id_cargo', self::QUOTA_CARGO_IDS)
            ->select(array_merge([
                'f._ID',
                'f.id_empleado',
                'f.nombre',
                'f.rol',
                'f.gestion',
            ], collect(array_keys(self::MARKETS))->map(fn (string $key): string => 'f.'.$key)->all()));
    }

    private function quotaLimits(): array
    {
        $limits = array_fill_keys(array_keys(self::MARKETS), 0);
        DB::connection('wordpress')
            ->table('wp_jet_cct_confi_sistema')
            ->whereIn('funcion', array_keys(self::MARKETS))
            ->get(['funcion', 'valor'])
            ->each(function (stdClass $row) use (&$limits): void {
                if (array_key_exists((string) $row->funcion, $limits)) {
                    $limits[(string) $row->funcion] = max(0, (int) $row->valor);
                }
            });

        return $limits;
    }

    private function quotaAssignedTotals(): array
    {
        $totals = array_fill_keys(array_keys(self::MARKETS), 0);
        $this->quotaEmployeesQuery()->get()->each(function (stdClass $employee) use (&$totals): void {
            foreach (array_keys(self::MARKETS) as $key) {
                $totals[$key] += max(0, (int) ($employee->{$key} ?? 0));
            }
        });

        return $totals;
    }

    private function quotaUsage(array $employeeIds): array
    {
        $employeeIds = array_values(array_filter(array_unique($employeeIds), fn ($id): bool => $id !== ''));
        $usage = [];
        foreach ($employeeIds as $employeeId) {
            $usage[$employeeId] = array_fill_keys(array_keys(self::MARKETS), ['used' => 0, 'pending' => 0]);
        }
        if ($employeeIds === []) {
            return $usage;
        }

        foreach (self::MARKETS as $key => $market) {
            DB::connection('wordpress')
                ->table('wp_jet_cct_inmuebles')
                ->whereIn('id_funcionario', $employeeIds)
                ->where('estado', 'Publico')
                ->where($market['property_column'], 'Si')
                ->selectRaw('id_funcionario, COUNT(*) as aggregate')
                ->groupBy('id_funcionario')
                ->get()
                ->each(function (stdClass $row) use (&$usage, $key): void {
                    $usage[(string) $row->id_funcionario][$key]['used'] = (int) $row->aggregate;
                });

            DB::connection('wordpress')
                ->table('wp_skc_destacado_solicitudes as s')
                ->leftJoin('wp_jet_cct_inmuebles as i', 'i.codigo', '=', 's.codigo_inmueble')
                ->whereIn('s.solicitado_por_id', $employeeIds)
                ->where('s.portal', $key)
                ->where('s.estado', 'pendiente')
                ->where(function (Builder $inactive) use ($market): void {
                    $inactive->whereNull('i.'.$market['property_column'])
                        ->orWhere('i.'.$market['property_column'], '<>', 'Si');
                })
                ->selectRaw('s.solicitado_por_id, COUNT(*) as aggregate')
                ->groupBy('s.solicitado_por_id')
                ->get()
                ->each(function (stdClass $row) use (&$usage, $key): void {
                    $usage[(string) $row->solicitado_por_id][$key]['pending'] = (int) $row->aggregate;
                });
        }

        return $usage;
    }

    private function quotaSummary(): array
    {
        $employees = $this->quotaEmployeesQuery()->get();
        $usage = $this->quotaUsage($employees->pluck('id_empleado')->map(fn ($id): string => (string) $id)->all());
        $limits = $this->quotaLimits();
        $assigned = $this->quotaAssignedTotals();
        $markets = [];

        foreach (self::MARKETS as $key => $market) {
            $used = collect($usage)->sum(fn (array $employee): int => (int) ($employee[$key]['used'] ?? 0));
            $pending = collect($usage)->sum(fn (array $employee): int => (int) ($employee[$key]['pending'] ?? 0));
            $available = $employees->sum(function (stdClass $employee) use ($usage, $key): int {
                $employeeId = (string) $employee->id_empleado;

                return max(0, (int) ($employee->{$key} ?? 0) - (int) ($usage[$employeeId][$key]['used'] ?? 0) - (int) ($usage[$employeeId][$key]['pending'] ?? 0));
            });
            $overcommitted = $employees->sum(function (stdClass $employee) use ($usage, $key): int {
                $employeeId = (string) $employee->id_empleado;

                return max(0, (int) ($usage[$employeeId][$key]['used'] ?? 0) + (int) ($usage[$employeeId][$key]['pending'] ?? 0) - (int) ($employee->{$key} ?? 0));
            });
            $markets[$key] = [
                'key' => $key,
                'label' => $market['label'],
                'limit' => (int) ($limits[$key] ?? 0),
                'assigned' => (int) ($assigned[$key] ?? 0),
                'used' => $used,
                'pending' => $pending,
                'available' => $available,
                'unassigned' => max(0, (int) ($limits[$key] ?? 0) - (int) ($assigned[$key] ?? 0)),
                'overcommitted' => $overcommitted,
            ];
        }

        return [
            'employees' => $employees->count(),
            'limit' => collect($markets)->sum('limit'),
            'assigned' => collect($markets)->sum('assigned'),
            'used' => collect($markets)->sum('used'),
            'pending' => collect($markets)->sum('pending'),
            'available' => collect($markets)->sum('available'),
            'unassigned' => collect($markets)->sum('unassigned'),
            'overcommitted' => collect($markets)->sum('overcommitted'),
            'markets' => $markets,
        ];
    }

    private function mapQuotaEmployee(stdClass $employee, array $usage): array
    {
        $employeeId = (string) $employee->id_empleado;
        $markets = [];
        foreach (self::MARKETS as $key => $market) {
            $assigned = max(0, (int) ($employee->{$key} ?? 0));
            $used = (int) ($usage[$employeeId][$key]['used'] ?? 0);
            $pending = (int) ($usage[$employeeId][$key]['pending'] ?? 0);
            $markets[$key] = [
                'key' => $key,
                'label' => $market['label'],
                'assigned' => $assigned,
                'used' => $used,
                'pending' => $pending,
                'available' => max(0, $assigned - $used - $pending),
                'overcommitted' => max(0, $used + $pending - $assigned),
            ];
        }

        return [
            'id' => (string) $employee->_ID,
            'employee_id' => $employeeId,
            'name' => $employee->nombre,
            'role' => $employee->rol,
            'management' => $employee->gestion,
            'markets' => $markets,
            'totals' => [
                'assigned' => collect($markets)->sum('assigned'),
                'used' => collect($markets)->sum('used'),
                'pending' => collect($markets)->sum('pending'),
                'available' => collect($markets)->sum('available'),
                'overcommitted' => collect($markets)->sum('overcommitted'),
            ],
        ];
    }

    private function mapRow(stdClass $row): array
    {
        return [
            'id' => (int) $row->id,
            'code' => (string) $row->code,
            'title' => trim(($row->property_type ?: 'Inmueble').' en '.($row->transaction_type ?: 'gestión').($row->neighborhood ? ' - '.$row->neighborhood : '')),
            'property_type' => $row->property_type,
            'transaction_type' => $row->transaction_type,
            'city' => $row->city,
            'neighborhood' => $row->neighborhood,
            'address' => $row->address,
            'consultant_id' => (string) ($row->consultant_id ?? ''),
            'consultant' => $row->consultant,
            'markets' => self::marketsFor($row),
            'highlighted_at' => $this->timestamp($row->property_highlighted_at ?: $row->history_highlighted_at),
            'highlighted_by_id' => (string) ($row->highlighted_by_id ?? ''),
            'highlighted_by' => $row->highlighted_by,
            'completed_by_id' => (string) ($row->completed_by_id ?? ''),
            'completed_by' => $row->completed_by,
            'requested_at' => $this->timestamp($row->requested_at),
            'completed_at' => $this->timestamp($row->completed_at),
            'reason' => $row->reason,
            'highlight_count' => (int) ($row->highlight_count ?? 0),
            'opportunity' => $row->opportunity,
            'negotiable' => $row->negotiable,
        ];
    }

    private function timestamp(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return is_numeric($value)
                ? Carbon::createFromTimestamp((int) $value)->toIso8601String()
                : Carbon::parse((string) $value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function isYes(mixed $value): bool
    {
        return self::isAffirmative($value);
    }
}
