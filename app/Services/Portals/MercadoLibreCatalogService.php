<?php

namespace App\Services\Portals;

use App\Models\Integration;
use App\Models\MercadoLibreCategoryMapping;
use App\Models\PortalCategory;
use App\Models\PortalCredential;
use Illuminate\Support\Str;
use RuntimeException;

class MercadoLibreCatalogService
{
    public function __construct(protected MercadoLibreClient $client) {}

    public function sync(PortalCredential $credential): array
    {
        $rootResult = $this->client->siteCategories($credential);
        $this->assertOk($rootResult);
        $root = collect($rootResult['data'])->first(
            fn (array $category) => $this->normalize($category['name'] ?? '') === 'inmuebles'
        );
        if (! $root) {
            throw new RuntimeException('Mercado Libre no devolvió la categoría raíz de Inmuebles para MCO.');
        }

        $integration = Integration::where('slug', 'mercadolibre')->firstOrFail();
        $leaves = [];
        $this->walk($root['id'], [], $credential, $integration->id, $leaves);
        $mapped = $this->mapLocalTypes($leaves);

        return [
            'root_category_id' => $root['id'],
            'categories' => PortalCategory::where('integration_id', $integration->id)->count(),
            'leaf_categories' => count($leaves),
            'mappings' => $mapped,
            'synced_at' => now()->toISOString(),
        ];
    }

    protected function walk(
        string $categoryId,
        array $parentPath,
        PortalCredential $credential,
        int $integrationId,
        array &$leaves
    ): void {
        $result = $this->client->getCategory($categoryId, $credential);
        $this->assertOk($result);
        $category = $result['data'];
        $path = $category['path_from_root'] ?? [...$parentPath, [
            'id' => $category['id'],
            'name' => $category['name'],
        ]];
        $children = $category['children_categories'] ?? [];
        $isLeaf = count($children) === 0;
        $attributes = [];

        if ($isLeaf) {
            $attributeResult = $this->client->categoryAttributes($categoryId, $credential);
            $this->assertOk($attributeResult);
            $attributes = $attributeResult['data'] ?? [];
            $leaves[] = [
                'id' => $categoryId,
                'name' => $category['name'] ?? $categoryId,
                'path' => $path,
                'settings' => $category['settings'] ?? [],
                'attributes' => $attributes,
            ];
        }

        PortalCategory::updateOrCreate(
            ['integration_id' => $integrationId, 'external_id' => $categoryId],
            [
                'name' => $category['name'] ?? $categoryId,
                'parent_external_id' => count($path) > 1 ? $path[count($path) - 2]['id'] : null,
                'level' => max(0, count($path) - 1),
                'metadata' => [
                    'path' => $path,
                    'settings' => $category['settings'] ?? [],
                    'attributes' => $attributes,
                    'is_leaf' => $isLeaf,
                ],
            ]
        );

        foreach ($children as $child) {
            $this->walk($child['id'], $path, $credential, $integrationId, $leaves);
        }
    }

    protected function mapLocalTypes(array $leaves): int
    {
        $aliases = [
            'apartamento' => ['apartamento', 'departamento'],
            'apartaestudio' => ['apartamento', 'departamento'],
            'bodega' => ['bodega', 'deposito', 'galpon'],
            'casa' => ['casa'],
            'casa-lote' => ['casa'],
            'consultorio' => ['consultorio', 'oficina'],
            'edificio' => ['edificio'],
            'finca' => ['finca', 'hacienda', 'quinta', 'agricola'],
            'hotel' => ['hotel', 'resort'],
            'local' => ['local'],
            'lote' => ['terreno', 'lote'],
            'oficina' => ['oficina'],
        ];
        $operationAliases = [
            'sale' => ['venta'],
            'rent' => ['arriendo', 'alquiler'],
        ];
        $mapped = 0;

        foreach ($aliases as $slug => $typeAliases) {
            foreach ($operationAliases as $operation => $opAliases) {
                $candidates = collect($leaves)
                    ->filter(function (array $leaf) use ($typeAliases, $opAliases): bool {
                        $path = $this->normalize(
                            collect($leaf['path'])->pluck('name')->implode(' ')
                        );
                        $typeMatches = collect($typeAliases)->contains(
                            fn (string $alias) => str_contains($path, $this->normalize($alias))
                        );
                        $operationMatches = collect($opAliases)->contains(
                            fn (string $alias) => str_contains($path, $this->normalize($alias))
                        );
                        $isDevelopment = str_contains($path, 'emprendimiento')
                            || str_contains($path, 'desarrollo')
                            || str_contains($path, 'proyecto');

                        return $typeMatches && $operationMatches && ! $isDevelopment;
                    })
                    ->sortByDesc(fn (array $leaf) => count($leaf['path']));
                $leaf = $candidates->first();
                if (! $leaf) {
                    continue;
                }

                MercadoLibreCategoryMapping::updateOrCreate(
                    ['property_type_slug' => $slug, 'operation' => $operation],
                    [
                        'category_id' => $leaf['id'],
                        'category_path' => $leaf['path'],
                        'settings' => $leaf['settings'],
                        'attributes' => $leaf['attributes'],
                        'is_leaf' => true,
                        'synced_at' => now(),
                    ]
                );
                $mapped++;
            }
        }

        return $mapped;
    }

    protected function normalize(string $value): string
    {
        return strtolower(Str::ascii(trim($value)));
    }

    protected function assertOk(array $result): void
    {
        if (! ($result['ok'] ?? false)) {
            throw new RuntimeException($this->client->errorMessage($result));
        }
    }
}
