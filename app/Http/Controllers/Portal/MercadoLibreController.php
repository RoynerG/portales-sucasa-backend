<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessMercadoLibreNotification;
use App\Models\Integration;
use App\Models\MercadoLibreNotification;
use App\Models\MercadoLibrePropertySetting;
use App\Models\PortalCredential;
use App\Models\Property;
use App\Models\PropertySyncStatus;
use App\Services\Portals\MercadoLibreCatalogService;
use App\Services\Portals\MercadoLibreClient;
use App\Services\Portals\MercadoLibrePropertyMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MercadoLibreController extends Controller
{
    public function __construct(
        protected MercadoLibreClient $client,
        protected MercadoLibrePropertyMapper $mapper,
        protected MercadoLibreCatalogService $catalog
    ) {}

    public function status(Request $request): JsonResponse
    {
        $credential = $this->sharedCredential(required: false);
        if (! $credential) {
            return response()->json(['Datos' => [
                'connected' => false,
                'configured' => $this->applicationConfigured(),
            ]]);
        }

        $packages = $this->client->packages($credential);
        $packageData = $packages['data'] ?? [];
        $packageList = array_is_list($packageData)
            ? $packageData
            : ($packageData['results'] ?? $packageData['packages'] ?? ($packageData ? [$packageData] : []));

        return response()->json(['Datos' => [
            'connected' => true,
            'configured' => $this->applicationConfigured(),
            'seller' => [
                'id' => $credential->data['external_user_id'] ?? null,
                'nickname' => $credential->data['nickname'] ?? null,
                'email' => $credential->data['email'] ?? null,
                'site_id' => $credential->data['site_id'] ?? null,
            ],
            'expires_at' => $credential->access_token_expires_at?->toIso8601String(),
            'packages' => $packages['ok'] ? $packageList : [],
            'package_error' => $packages['ok'] ? null : $this->client->errorMessage($packages),
        ]]);
    }

    public function redirect(Request $request): JsonResponse
    {
        $this->adminOnly($request);
        abort_unless($this->applicationConfigured(), 422, 'Configura Client ID, Client Secret y Redirect URI de Mercado Libre.');

        return response()->json(['Datos' => [
            'authorize_url' => $this->client->authorizeUrl($request->user()->id),
        ]]);
    }

    public function callback(Request $request)
    {
        $code = (string) $request->query('code');
        $state = (string) $request->query('state');
        abort_unless($code !== '' && $state !== '', 400, 'Parámetros OAuth inválidos.');
        $this->client->exchangeCode($code, $state);

        return redirect()->away(config('frontend.frontend_url').'/?ml=connected#/integrations');
    }

    public function disconnect(Request $request): JsonResponse
    {
        $this->adminOnly($request);
        $this->sharedCredential(required: false)?->delete();

        return response()->json(['Datos' => ['connected' => false]]);
    }

    public function syncCatalog(Request $request): JsonResponse
    {
        $this->adminOnly($request);
        $result = $this->catalog->sync($this->sharedCredential());

        return response()->json(['Datos' => ['ok' => true, ...$result]]);
    }

    public function preflight(Request $request, string $code): JsonResponse
    {
        $this->operatorOnly($request);
        $credential = $this->sharedCredential();
        $results = [];

        foreach ($this->requestedOperations($request, $code) as $operation) {
            $mapped = $this->mapper->map($code, $operation, $credential);
            $validation = null;
            if ($mapped['errors'] === []) {
                $validation = $this->client->validateItem($mapped['payload'], $credential);
                if (! $validation['ok']) {
                    $mapped['errors'][] = $this->client->errorMessage($validation);
                }
            }
            $results[] = $this->resultPayload(
                $mapped['errors'] === [],
                $operation,
                $mapped['errors'] === [] ? 'validated' : 'error',
                response: $validation,
                warnings: $mapped['warnings'],
                errors: $mapped['errors'],
                extra: [
                    'payload' => $mapped['payload'],
                    'source' => $mapped['source'],
                    'description' => $mapped['description'],
                    'show_exact_address' => $mapped['show_exact_address'],
                ]
            );
        }

        return response()->json(['Datos' => $this->aggregate($results)]);
    }

    public function saveSettings(Request $request, string $code): JsonResponse
    {
        $this->operatorOnly($request);
        $data = $request->validate([
            'operation' => ['required', 'in:sale,rent'],
            'listing_type_id' => ['sometimes', 'in:silver,gold,gold_premium'],
            'category_id' => ['nullable', 'string', 'max:50'],
            'attributes' => ['nullable', 'array'],
            'location' => ['nullable', 'array'],
            'show_exact_address' => ['nullable', 'boolean'],
        ]);
        $property = Property::where('code', $code)->first();
        if (! $property) {
            $mapped = $this->mapper->map($code, $data['operation'], $this->sharedCredential());
            $property = $mapped['property'];
        }
        $setting = MercadoLibrePropertySetting::updateOrCreate(
            ['property_id' => $property->id, 'operation' => $data['operation']],
            collect($data)->except('operation')->all()
        );

        return response()->json(['Datos' => ['ok' => true, 'settings' => $setting]]);
    }

    public function publish(Request $request, string $code): JsonResponse
    {
        $this->operatorOnly($request);
        $credential = $this->sharedCredential();
        $results = [];

        foreach ($this->requestedOperations($request, $code) as $operation) {
            $mapped = $this->mapper->map($code, $operation, $credential);
            if ($mapped['errors'] !== []) {
                $results[] = $this->resultPayload(false, $operation, 'error', warnings: $mapped['warnings'], errors: $mapped['errors']);

                continue;
            }
            $existing = $this->syncStatus($mapped['property'], $operation);
            if ($existing?->external_id && $existing->sync_status !== 'error') {
                $results[] = $this->resultPayload(
                    false,
                    $operation,
                    $existing->sync_status,
                    $existing->external_id,
                    $existing->external_url,
                    errors: ['El inmueble ya tiene una publicación para esta operación; utiliza Actualizar.']
                );

                continue;
            }

            $validation = $this->client->validateItem($mapped['payload'], $credential);
            if (! $validation['ok']) {
                $message = $this->client->errorMessage($validation);
                $this->saveStatus($mapped['property'], $operation, 'error', response: $validation, error: $message);
                $results[] = $this->resultPayload(false, $operation, 'error', response: $validation, warnings: $mapped['warnings'], errors: [$message]);

                continue;
            }

            $created = $this->client->createItem($mapped['payload'], $credential);
            if (! $created['ok'] || empty($created['data']['id'])) {
                $message = $this->client->errorMessage($created);
                $this->saveStatus($mapped['property'], $operation, 'error', response: $created, error: $message);
                $results[] = $this->resultPayload(false, $operation, 'error', response: $created, warnings: $mapped['warnings'], errors: [$message]);

                continue;
            }

            $itemId = $created['data']['id'];
            $publicUrl = $created['data']['permalink'] ?? null;
            $this->saveStatus($mapped['property'], $operation, 'synced', $itemId, $publicUrl, $created);
            $descriptionResult = $mapped['description'] !== ''
                ? $this->client->createDescription($itemId, $mapped['description'], $credential)
                : ['ok' => true, 'status' => 204, 'data' => null];
            $visibilityResult = $mapped['show_exact_address']
                ? ['ok' => true, 'status' => 204, 'data' => null]
                : $this->client->setAddressVisibility($itemId, false, $credential);
            $errors = [];
            if (! $descriptionResult['ok']) {
                $errors[] = $this->client->errorMessage($descriptionResult);
            }
            if (! $visibilityResult['ok']) {
                $errors[] = $this->client->errorMessage($visibilityResult);
            }
            $status = $errors === [] ? 'synced' : 'error';
            $combined = [
                'item' => $created,
                'description' => $descriptionResult,
                'address_visibility' => $visibilityResult,
            ];
            $this->saveStatus(
                $mapped['property'],
                $operation,
                $status,
                $itemId,
                $publicUrl,
                $combined,
                $errors === [] ? null : implode(' ', $errors)
            );
            $results[] = $this->resultPayload(
                $errors === [],
                $operation,
                $status,
                $itemId,
                $publicUrl,
                $combined,
                $mapped['warnings'],
                $errors
            );
        }

        return response()->json(['Datos' => $this->aggregate($results)]);
    }

    public function update(Request $request, string $code): JsonResponse
    {
        $this->operatorOnly($request);
        $credential = $this->sharedCredential();
        $results = [];

        foreach ($this->requestedOperations($request, $code) as $operation) {
            $mapped = $this->mapper->map($code, $operation, $credential);
            $sync = $this->syncStatus($mapped['property'], $operation);
            if (! $sync?->external_id) {
                $results[] = $this->resultPayload(false, $operation, 'error', errors: ['Esta operación no está publicada.']);

                continue;
            }
            if ($mapped['errors'] !== []) {
                $results[] = $this->resultPayload(false, $operation, 'error', $sync->external_id, $sync->external_url, warnings: $mapped['warnings'], errors: $mapped['errors']);

                continue;
            }

            $itemResult = $this->client->updateItem($sync->external_id, $mapped['update_payload'], $credential);
            $descriptionResult = $itemResult['ok'] && $mapped['description'] !== ''
                ? $this->client->updateDescription($sync->external_id, $mapped['description'], $credential)
                : ['ok' => true, 'status' => 204, 'data' => null];
            if (! $descriptionResult['ok'] && $descriptionResult['status'] === 400) {
                $descriptionResult = $this->client->createDescription($sync->external_id, $mapped['description'], $credential);
            }
            $visibilityResult = $itemResult['ok']
                ? $this->client->setAddressVisibility($sync->external_id, $mapped['show_exact_address'], $credential)
                : ['ok' => true, 'status' => 204, 'data' => null];
            $errors = collect([$itemResult, $descriptionResult, $visibilityResult])
                ->reject(fn (array $result) => $result['ok'])
                ->map(fn (array $result) => $this->client->errorMessage($result))
                ->values()
                ->all();
            $status = $errors === [] ? 'synced' : 'error';
            $response = ['item' => $itemResult, 'description' => $descriptionResult, 'address_visibility' => $visibilityResult];
            $url = $itemResult['data']['permalink'] ?? $sync->external_url;
            $this->saveStatus($mapped['property'], $operation, $status, $sync->external_id, $url, $response, $errors === [] ? null : implode(' ', $errors));
            $results[] = $this->resultPayload($errors === [], $operation, $status, $sync->external_id, $url, $response, $mapped['warnings'], $errors);
        }

        return response()->json(['Datos' => $this->aggregate($results)]);
    }

    public function pause(Request $request, string $code): JsonResponse
    {
        return $this->changeStatus($request, $code, 'paused');
    }

    public function activate(Request $request, string $code): JsonResponse
    {
        return $this->changeStatus($request, $code, 'active');
    }

    public function close(Request $request, string $code): JsonResponse
    {
        $request->validate(['confirmed' => ['accepted']]);

        return $this->changeStatus($request, $code, 'closed');
    }

    public function verify(Request $request, string $code): JsonResponse
    {
        $this->operatorOnly($request);
        $credential = $this->sharedCredential();
        $property = Property::where('code', $code)->firstOrFail();
        $results = [];

        foreach ($this->requestedOperations($request, $code) as $operation) {
            $sync = $this->syncStatus($property, $operation);
            if (! $sync?->external_id) {
                $results[] = $this->resultPayload(false, $operation, 'error', errors: ['Esta operación no está publicada.']);

                continue;
            }
            $result = $this->client->getItem($sync->external_id, $credential);
            $status = $result['ok'] ? $this->localStatus($result['data']['status'] ?? null) : 'error';
            $error = $result['ok'] ? null : $this->client->errorMessage($result);
            $url = $result['data']['permalink'] ?? $sync->external_url;
            $this->saveStatus($property, $operation, $status, $sync->external_id, $url, $result, $error);
            $results[] = $this->resultPayload($result['ok'], $operation, $status, $sync->external_id, $url, $result, errors: $error ? [$error] : []);
        }

        return response()->json(['Datos' => $this->aggregate($results)]);
    }

    public function webhook(Request $request): JsonResponse
    {
        $payload = $request->all();
        $credential = $this->sharedCredential(required: false);
        $topic = (string) ($payload['topic'] ?? '');
        $resource = (string) ($payload['resource'] ?? '');
        $applicationId = (string) ($payload['application_id'] ?? '');
        $externalUserId = (string) ($payload['user_id'] ?? '');

        if (! $credential
            || $topic !== 'items'
            || ! preg_match('~^/items/MCO\d+$~', $resource)
            || $applicationId !== (string) config('portals.mercadolibre.client_id')
            || $externalUserId !== (string) ($credential->data['external_user_id'] ?? '')) {
            return response()->json(['Datos' => 'IGNORED']);
        }

        $notificationId = (string) ($payload['_id'] ?? hash('sha256', json_encode($payload)));
        $notification = MercadoLibreNotification::firstOrCreate(
            ['notification_id' => $notificationId],
            [
                'topic' => $topic,
                'resource' => $resource,
                'external_user_id' => $externalUserId,
                'application_id' => $applicationId,
                'payload' => $payload,
                'status' => 'pending',
            ]
        );
        if ($notification->wasRecentlyCreated) {
            ProcessMercadoLibreNotification::dispatch($notification->id)
                ->onQueue(config('portals.mercadolibre.webhook_queue'));
        }

        return response()->json(['Datos' => 'OK']);
    }

    protected function changeStatus(Request $request, string $code, string $target): JsonResponse
    {
        $this->operatorOnly($request);
        $credential = $this->sharedCredential();
        $property = Property::where('code', $code)->firstOrFail();
        $results = [];

        foreach ($this->requestedOperations($request, $code) as $operation) {
            $sync = $this->syncStatus($property, $operation);
            if (! $sync?->external_id) {
                $results[] = $this->resultPayload(false, $operation, 'error', errors: ['Esta operación no está publicada.']);

                continue;
            }
            $result = $this->client->changeStatus($sync->external_id, $target, $credential);
            $status = $result['ok'] ? $this->localStatus($target) : 'error';
            $error = $result['ok'] ? null : $this->client->errorMessage($result);
            $url = $target === 'closed' ? null : ($result['data']['permalink'] ?? $sync->external_url);
            $this->saveStatus($property, $operation, $status, $sync->external_id, $url, $result, $error);
            $results[] = $this->resultPayload($result['ok'], $operation, $status, $sync->external_id, $url, $result, errors: $error ? [$error] : []);
        }

        return response()->json(['Datos' => $this->aggregate($results)]);
    }

    protected function requestedOperations(Request $request, string $code): array
    {
        $available = $this->mapper->operations($code);
        $requested = (string) ($request->input('operation') ?? $request->query('operation', 'all'));
        if ($requested === 'all') {
            return $available;
        }
        abort_unless(in_array($requested, $available, true), 422, 'La operación solicitada no aplica al inmueble.');

        return [$requested];
    }

    protected function sharedCredential(bool $required = true): ?PortalCredential
    {
        $credential = PortalCredential::where([
            'integration_id' => $this->integration()->id,
            'account_key' => config('portals.mercadolibre.account_key'),
        ])->first();
        if ($required) {
            abort_unless($credential, 401, 'Conecta la cuenta empresarial de Mercado Libre.');
        }

        return $credential;
    }

    protected function integration(): Integration
    {
        return Integration::where('slug', 'mercadolibre')->firstOrFail();
    }

    protected function syncStatus(Property $property, string $operation): ?PropertySyncStatus
    {
        return $property->syncStatuses()->where([
            'integration_id' => $this->integration()->id,
            'environment' => config('portals.mercadolibre.environment'),
            'portal_variant' => $operation,
        ])->first();
    }

    protected function saveStatus(
        Property $property,
        string $operation,
        string $status,
        ?string $externalId = null,
        ?string $externalUrl = null,
        ?array $response = null,
        ?string $error = null
    ): PropertySyncStatus {
        return PropertySyncStatus::updateOrCreate(
            [
                'property_id' => $property->id,
                'integration_id' => $this->integration()->id,
                'environment' => config('portals.mercadolibre.environment'),
                'portal_variant' => $operation,
            ],
            [
                'sync_status' => $status,
                'external_id' => $externalId,
                'external_url' => $externalUrl,
                'last_response' => $response,
                'last_error' => $error,
                'last_attempt_at' => now(),
                'last_synced_at' => $status === 'synced' ? now() : null,
            ]
        );
    }

    protected function resultPayload(
        bool $ok,
        string $operation,
        string $status,
        ?string $externalId = null,
        ?string $publicUrl = null,
        ?array $response = null,
        array $warnings = [],
        array $errors = [],
        array $extra = []
    ): array {
        return [
            'ok' => $ok,
            'operation' => $operation,
            'sync_status' => $status,
            'external_id' => $externalId,
            'public_url' => $publicUrl,
            'warnings' => $warnings,
            'errors' => $errors,
            'response' => $response,
            ...$extra,
        ];
    }

    protected function aggregate(array $results): array
    {
        if (count($results) === 1) {
            return $results[0];
        }

        return [
            'ok' => collect($results)->every(fn (array $result) => $result['ok']),
            'operation' => 'all',
            'sync_status' => collect($results)->every(fn (array $result) => $result['sync_status'] === 'synced')
                ? 'synced'
                : (collect($results)->contains(fn (array $result) => $result['sync_status'] === 'error') ? 'error' : 'pending'),
            'external_id' => null,
            'public_url' => null,
            'operations' => $results,
            'warnings' => collect($results)->flatMap(fn (array $result) => $result['warnings'])->unique()->values()->all(),
            'errors' => collect($results)->flatMap(fn (array $result) => $result['errors'])->unique()->values()->all(),
            'response' => ['operations' => $results],
        ];
    }

    protected function localStatus(?string $status): string
    {
        return match ($status) {
            'active' => 'synced',
            'paused' => 'paused',
            'closed' => 'closed',
            default => 'error',
        };
    }

    protected function applicationConfigured(): bool
    {
        return (bool) config('portals.mercadolibre.client_id')
            && (bool) config('portals.mercadolibre.client_secret')
            && (bool) config('portals.mercadolibre.redirect_uri');
    }

    protected function adminOnly(Request $request): void
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Solo administradores y gerentes pueden administrar la conexión.');
    }

    protected function operatorOnly(Request $request): void
    {
        abort_if($request->user()?->role === 'viewer', 403, 'El usuario de consulta no puede operar portales.');
    }
}
