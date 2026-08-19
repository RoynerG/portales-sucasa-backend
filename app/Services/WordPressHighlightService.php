<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use OutOfBoundsException;
use stdClass;

class WordPressHighlightService
{
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
                'observacion' => 'Se liberó el cupo de destacado del inmueble desde el panel de portales.',
                'cct_author_id' => (int) $employeeId,
                'cct_created' => now(),
                'cct_modified' => now(),
            ]);

            return [
                'code' => (string) (($property->codigo ?? '') ?: $property->_ID),
                'released_markets' => $markets,
                'message' => 'Destacado desarmado y cupo liberado correctamente.',
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
