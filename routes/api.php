<?php

use App\Http\Controllers\Api\BrandingController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ConsultantController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\PropertyController;
use App\Http\Controllers\Api\PropertyHighlightController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Portal\CiencuadrasController;
use App\Http\Controllers\Portal\CiencuadrasMappingController;
use App\Http\Controllers\Portal\FincaraizController;
use App\Http\Controllers\Portal\FincaraizNeighborhoodController;
use App\Http\Controllers\Portal\MercadoLibreController;
use App\Http\Controllers\Portal\PortalAutomationController;
use App\Http\Controllers\Portal\PortalBulkController;
use App\Http\Controllers\Portal\PortalCatalogAuditController;
use App\Http\Controllers\Portal\PortalErrorController;
use App\Http\Controllers\Portal\PortalRecoveryController;
use App\Http\Controllers\Portal\PortalResetController;
use App\Http\Controllers\Portal\ProppitController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['Datos' => ['status' => 'ok', 'time' => now()]]));
Route::get('/branding', [BrandingController::class, 'show']);

Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // Catálogos
    Route::prefix('catalog')->group(function () {
        Route::get('/cities', [CatalogController::class, 'cities']);
        Route::get('/neighborhoods', [CatalogController::class, 'neighborhoods']);
        Route::get('/property-types', [CatalogController::class, 'propertyTypes']);
        Route::get('/transaction-types', [CatalogController::class, 'transactionTypes']);
        Route::get('/destinations', [CatalogController::class, 'destinations']);
        Route::get('/features', [CatalogController::class, 'features']);
    });

    // General
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/messages', [UserController::class, 'messages']);

    // Integraciones
    Route::get('/integrations', [IntegrationController::class, 'index']);

    // Propiedades
    Route::get('/properties', [PropertyController::class, 'index']);
    Route::post('/properties', [PropertyController::class, 'store']);
    Route::get('/properties/stats', [PropertyController::class, 'statsByStatus']);
    Route::get('/properties/portal-summary', [PropertyController::class, 'portalSummary']);
    Route::get('/properties/distribution', [PropertyController::class, 'distribution']);
    Route::get('/properties/highlights', [PropertyHighlightController::class, 'index']);
    Route::delete('/properties/{code}/highlight', [PropertyHighlightController::class, 'destroy'])->middleware('portal-reset');
    Route::get('/properties/{code}', [PropertyController::class, 'show']);
    Route::patch('/properties/{code}', [PropertyController::class, 'update']);
    Route::delete('/properties/{code}', [PropertyController::class, 'destroy']);
    Route::post('/properties/{code}/sync/{integrationId}', [PropertyController::class, 'syncStatus']);

    // Funcionarios
    Route::get('/consultants', [ConsultantController::class, 'index']);
    Route::get('/consultants/{consultant}', [ConsultantController::class, 'show']);

    // Portales
    Route::prefix('portals')->group(function () {
        Route::middleware('portal-reset')->prefix('settings')->group(function () {
            Route::get('/reset-preview', [PortalResetController::class, 'preview']);
            Route::post('/reset', [PortalResetController::class, 'reset']);
        });

        Route::get('/mercadolibre/status', [MercadoLibreController::class, 'status']);
        Route::get('/mercadolibre/authorize', [MercadoLibreController::class, 'redirect']);
        Route::post('/mercadolibre/disconnect', [MercadoLibreController::class, 'disconnect']);
        Route::post('/mercadolibre/catalog/sync', [MercadoLibreController::class, 'syncCatalog']);
        Route::get('/properties/{code}/mercadolibre/preflight', [MercadoLibreController::class, 'preflight']);
        Route::patch('/properties/{code}/mercadolibre/settings', [MercadoLibreController::class, 'saveSettings']);
        Route::post('/properties/{code}/mercadolibre/publish', [MercadoLibreController::class, 'publish']);
        Route::post('/properties/{code}/mercadolibre/update', [MercadoLibreController::class, 'update']);
        Route::post('/properties/{code}/mercadolibre/pause', [MercadoLibreController::class, 'pause']);
        Route::post('/properties/{code}/mercadolibre/activate', [MercadoLibreController::class, 'activate']);
        Route::post('/properties/{code}/mercadolibre/close', [MercadoLibreController::class, 'close']);
        Route::post('/properties/{code}/mercadolibre/verify', [MercadoLibreController::class, 'verify']);

        Route::get('/fincaraiz/status', [FincaraizController::class, 'status']);
        Route::patch('/fincaraiz/settings', [FincaraizController::class, 'saveSettings']);
        Route::get('/fincaraiz/client', [FincaraizController::class, 'clientInfo']);
        Route::get('/fincaraiz/listings', [FincaraizController::class, 'listings']);
        Route::post('/fincaraiz/reconcile', [FincaraizController::class, 'reconcile']);
        Route::post('/fincaraiz/retire', [FincaraizController::class, 'retire']);
        Route::get('/fincaraiz/locations', [FincaraizController::class, 'locations']);
        Route::get('/fincaraiz/neighborhoods', [FincaraizNeighborhoodController::class, 'index']);
        Route::patch('/fincaraiz/neighborhoods/{id}', [FincaraizNeighborhoodController::class, 'update']);
        Route::post('/fincaraiz/webhook/subscribe', [FincaraizController::class, 'subscribeWebhook']);
        Route::get('/automation', [PortalAutomationController::class, 'index']);
        Route::get('/automation/catalog-audit', [PortalCatalogAuditController::class, 'index']);
        Route::post('/automation/catalog-audit/{portal}', [PortalCatalogAuditController::class, 'verify']);
        Route::post('/automation/catalog-audit/fincaraiz/export', [PortalCatalogAuditController::class, 'importFincaraizExport']);
        Route::get('/errors', [PortalErrorController::class, 'index']);
        Route::post('/recovery/{portal}', [PortalRecoveryController::class, 'recover']);
        Route::get('/{portal}/bulk-candidates', [PortalBulkController::class, 'candidates']);
        Route::get('/properties/{code}/fincaraiz/payload', [FincaraizController::class, 'payload']);
        Route::patch('/properties/{code}/fincaraiz/location', [FincaraizController::class, 'saveLocation']);
        Route::post('/properties/{code}/fincaraiz/publish', [FincaraizController::class, 'publish']);
        Route::post('/properties/{code}/fincaraiz/update', [FincaraizController::class, 'update']);
        Route::post('/properties/{code}/fincaraiz/pause', [FincaraizController::class, 'pause']);
        Route::post('/properties/{code}/fincaraiz/activate', [FincaraizController::class, 'activate']);
        Route::post('/properties/{code}/fincaraiz/verify', [FincaraizController::class, 'verify']);

        Route::post('/ciencuadras/login', [CiencuadrasController::class, 'login']);
        Route::post('/ciencuadras/bulk', [CiencuadrasController::class, 'bulk']);
        Route::get('/ciencuadras/mappings', [CiencuadrasMappingController::class, 'index']);
        Route::post('/ciencuadras/mappings/import-public-codes', [CiencuadrasMappingController::class, 'importPublicCodes']);
        Route::patch('/ciencuadras/mappings/cities/{id}', [CiencuadrasMappingController::class, 'updateCity']);
        Route::patch('/ciencuadras/mappings/neighborhoods/{id}', [CiencuadrasMappingController::class, 'updateNeighborhood']);
        Route::get('/properties/{code}/ciencuadras/payload', [CiencuadrasController::class, 'payload']);
        Route::post('/properties/{code}/ciencuadras/publish', [CiencuadrasController::class, 'publish']);
        Route::post('/properties/{code}/ciencuadras/update', [CiencuadrasController::class, 'update']);
        Route::post('/properties/{code}/ciencuadras/pause', [CiencuadrasController::class, 'pause']);
        Route::post('/properties/{code}/ciencuadras/delete', [CiencuadrasController::class, 'delete']);
        Route::post('/properties/{code}/ciencuadras/verify', [CiencuadrasController::class, 'consult']);

        Route::post('/proppit/login', [ProppitController::class, 'login']);
        Route::get('/properties/{code}/proppit/payload', [ProppitController::class, 'payload']);
        Route::post('/properties/{code}/proppit/publish', [ProppitController::class, 'publish']);
        Route::post('/properties/{code}/proppit/update', [ProppitController::class, 'update']);
        Route::post('/properties/{code}/proppit/pause', [ProppitController::class, 'pause']);
        Route::post('/properties/{code}/proppit/verify', [ProppitController::class, 'verify']);

    });
});

// Callbacks y webhooks (sin auth, validados por su propio mecanismo)
Route::get('/portals/mercadolibre/callback', [MercadoLibreController::class, 'callback'])->name('ml.callback');
Route::post('/portals/mercadolibre/webhook', [MercadoLibreController::class, 'webhook']);
Route::post('/portals/fincaraiz/webhook', [FincaraizController::class, 'webhook']);
