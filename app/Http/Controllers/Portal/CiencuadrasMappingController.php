<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CiencuadrasMappingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureColumns();

        $search = trim((string) $request->query('search', ''));
        $city = trim((string) $request->query('city', ''));

        $citiesQuery = DB::connection('wordpress')
            ->table('wp_jet_cct_ciudades');

        if ($search !== '') {
            $citiesQuery->where(function ($query) use ($search) {
                $query->where('ciudad', 'like', "%{$search}%")
                    ->orWhere('departamento', 'like', "%{$search}%");
            });
        }

        $cities = $citiesQuery
            ->orderBy('ciudad')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->_ID,
                'city' => $row->ciudad,
                'department' => $row->departamento,
                'country' => $row->pais,
                'ciencuadras_city_id' => $row->ciencuadras_city_id ? (int) $row->ciencuadras_city_id : null,
            ]);

        $neighborhoodsQuery = DB::connection('wordpress')
            ->table('wp_jet_cct_barrios')
            ->where('cct_status', 'publish');

        if ($city !== '') {
            $neighborhoodsQuery->where('ciudad', $city);
        }

        if ($search !== '') {
            $neighborhoodsQuery->where(function ($query) use ($search) {
                $query->where('barrio', 'like', "%{$search}%")
                    ->orWhere('ciudad', 'like', "%{$search}%")
                    ->orWhere('departamento', 'like', "%{$search}%");
            });
        }

        $neighborhoods = $neighborhoodsQuery
            ->orderBy('ciudad')
            ->orderBy('barrio')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->_ID,
                'neighborhood' => $row->barrio,
                'city' => $row->ciudad,
                'department' => $row->departamento,
                'country' => $row->pais,
                'postal_code' => $row->codigo_postal,
                'ciencuadras_locality_id' => $row->ciencuadras_locality_id ? (int) $row->ciencuadras_locality_id : null,
            ]);

        return response()->json(['Datos' => [
            'summary' => [
                'cities_total' => $cities->count(),
                'cities_configured' => $cities->whereNotNull('ciencuadras_city_id')->count(),
                'neighborhoods_total' => $neighborhoods->count(),
                'neighborhoods_configured' => $neighborhoods->whereNotNull('ciencuadras_locality_id')->count(),
            ],
            'cities' => $cities->values(),
            'neighborhoods' => $neighborhoods->values(),
        ]]);
    }

    public function updateCity(Request $request, int $id): JsonResponse
    {
        $this->ensureColumns();

        $data = $request->validate([
            'ciencuadras_city_id' => ['nullable', 'integer', 'min:1'],
            'country' => ['nullable', 'string', 'max:80'],
        ]);

        DB::connection('wordpress')
            ->table('wp_jet_cct_ciudades')
            ->where('_ID', $id)
            ->update([
                'ciencuadras_city_id' => $data['ciencuadras_city_id'] ?? null,
                'pais' => $data['country'] ?? null,
            ]);

        return response()->json(['Datos' => ['ok' => true]]);
    }

    public function updateNeighborhood(Request $request, int $id): JsonResponse
    {
        $this->ensureColumns();

        $data = $request->validate([
            'ciencuadras_locality_id' => ['nullable', 'integer', 'min:1'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:80'],
        ]);

        DB::connection('wordpress')
            ->table('wp_jet_cct_barrios')
            ->where('_ID', $id)
            ->update([
                'ciencuadras_locality_id' => $data['ciencuadras_locality_id'] ?? null,
                'codigo_postal' => $this->cleanCode($data['postal_code'] ?? null),
                'pais' => $data['country'] ?? null,
            ]);

        return response()->json(['Datos' => ['ok' => true]]);
    }

    public function importPublicCodes(): JsonResponse
    {
        $this->ensureColumns();

        $divipola = Http::timeout(45)
            ->get('https://www.datos.gov.co/resource/gdxc-w37w.json?%24limit=5000')
            ->throw()
            ->json();
        $postal = Http::timeout(60)
            ->get('https://www.datos.gov.co/resource/ixig-z8b5.json?%24limit=50000')
            ->throw()
            ->json();

        $daneByCity = collect($divipola)->mapWithKeys(fn (array $row) => [
            $this->locationKey($row['nom_mpio'] ?? '', $row['dpto'] ?? '') => $this->cleanCode($row['cod_mpio'] ?? null),
        ]);
        $daneByUniqueCity = collect($divipola)
            ->groupBy(fn (array $row) => $this->normalize($row['nom_mpio'] ?? ''))
            ->filter(fn ($rows, string $city) => $city !== '' && $rows->count() === 1)
            ->map(fn ($rows) => $this->cleanCode($rows->first()['cod_mpio'] ?? null));

        $aliases = [
            'bogota' => '11001',
            'bogota dc' => '11001',
            'bogaota d c' => '11001',
            'armenia' => '63001',
            'cali' => '76001',
            'buga' => '76111',
            'chia' => '25175',
            'cucuta' => '54001',
            'florencia' => '18001',
            'fusagasuga' => '25290',
            'ibague' => '73001',
            'jamundi' => '76364',
            'mariquita' => '73443',
            'medellin' => '05001',
            'mompox' => '13468',
            'monteria' => '23001',
            'popayan' => '19001',
            'santafe de antioquia' => '05042',
            'san andres y providencia' => '88001',
            'tocancipa' => '25817',
            'tolu' => '70820',
            'tulua' => '76834',
            'tumaco' => '52835',
            'zipaquira' => '25899',
        ];

        $postalRows = collect($postal)->map(fn (array $row) => [
            'city_key' => $this->locationKey($row['nombre_municipio'] ?? '', $row['nombre_departamento'] ?? ''),
            'postal_code' => $this->cleanCode($row['codigo_postal'] ?? null),
            'neighborhoods' => $this->normalize($row['barrios_contenidos_en_el'] ?? ''),
        ])->filter(fn (array $row) => $row['postal_code']);

        $db = DB::connection('wordpress');
        $citiesUpdated = 0;
        $neighborhoodsUpdated = 0;

        foreach ($db->table('wp_jet_cct_ciudades')->get() as $city) {
            $dane = $daneByCity[$this->locationKey($city->ciudad, $city->departamento)] ?? null;
            if (! $dane) {
                $normalizedCity = $this->normalize($city->ciudad);
                $dane = $aliases[$normalizedCity] ?? $daneByUniqueCity[$normalizedCity] ?? null;
            }

            $updates = ['pais' => $city->pais ?: 'Colombia'];
            if ($dane && ! $city->ciencuadras_city_id) {
                $updates['ciencuadras_city_id'] = $dane;
            }

            if ($updates !== ['pais' => $city->pais]) {
                $db->table('wp_jet_cct_ciudades')->where('_ID', $city->_ID)->update($updates);
                $citiesUpdated++;
            }
        }

        foreach ($db->table('wp_jet_cct_barrios')->get() as $neighborhood) {
            $cityKey = $this->locationKey($neighborhood->ciudad, $neighborhood->departamento);
            $needle = $this->normalize($neighborhood->barrio);
            $postalCode = null;

            if ($needle !== '') {
                $match = $postalRows->first(fn (array $row) =>
                    $row['city_key'] === $cityKey
                    && $row['neighborhoods'] !== ''
                    && $row['neighborhoods'] !== 'sin informacion de barrios'
                    && str_contains($row['neighborhoods'], $needle)
                );
                $postalCode = $match['postal_code'] ?? null;
            }

            $updates = ['pais' => $neighborhood->pais ?: 'Colombia'];
            if ($postalCode && ! $neighborhood->codigo_postal) {
                $updates['codigo_postal'] = $postalCode;
            }

            if ($updates !== ['pais' => $neighborhood->pais]) {
                $db->table('wp_jet_cct_barrios')->where('_ID', $neighborhood->_ID)->update($updates);
                $neighborhoodsUpdated++;
            }
        }

        return response()->json(['Datos' => [
            'ok' => true,
            'cities_updated' => $citiesUpdated,
            'neighborhoods_updated' => $neighborhoodsUpdated,
            'sources' => [
                'divipola' => 'https://www.datos.gov.co/Mapas-Nacionales/DIVIPOLA-C-digos-municipios/gdxc-w37w',
                'postal_codes' => 'https://www.datos.gov.co/Ordenamiento-Territorial/C-digos-Postales-Nacionales/ixig-z8b5',
            ],
        ]]);
    }

    protected function ensureColumns(): void
    {
        abort_unless(
            Schema::connection('wordpress')->hasColumn('wp_jet_cct_ciudades', 'ciencuadras_city_id')
            && Schema::connection('wordpress')->hasColumn('wp_jet_cct_barrios', 'ciencuadras_locality_id'),
            422,
            'Faltan columnas ciencuadras_city_id o ciencuadras_locality_id en WordPress.'
        );
    }

    protected function locationKey(?string $city, ?string $department): string
    {
        return trim($this->normalize($city) . '|' . $this->normalize($department), '|');
    }

    protected function normalize(?string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::ascii(strtolower((string) $value))));
    }

    protected function cleanCode(?string $value): ?string
    {
        $code = preg_replace('/\D+/', '', (string) $value);

        return $code === '' ? null : $code;
    }
}
