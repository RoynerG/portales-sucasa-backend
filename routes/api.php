<?php

use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\BrandingController;
use App\Http\Controllers\Api\ConsultantController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\PropertyController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Portal\CiencuadrasController;
use App\Http\Controllers\Portal\CiencuadrasMappingController;
use App\Http\Controllers\Portal\FincaraizController;
use App\Http\Controllers\Portal\MercadoLibreController;
use App\Http\Controllers\Portal\PortalErrorController;
use App\Http\Controllers\Portal\ProppitController;
use App\Http\Controllers\Portal\XmlController;
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
        Route::get('/cities',          [CatalogController::class, 'cities']);
        Route::get('/neighborhoods',   [CatalogController::class, 'neighborhoods']);
        Route::get('/property-types',  [CatalogController::class, 'propertyTypes']);
        Route::get('/transaction-types',[CatalogController::class, 'transactionTypes']);
        Route::get('/destinations',     [CatalogController::class, 'destinations']);
        Route::get('/features',        [CatalogController::class, 'features']);
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
    Route::get('/properties/distribution', [PropertyController::class, 'distribution']);
    Route::get('/properties/{code}', [PropertyController::class, 'show']);
    Route::patch('/properties/{code}', [PropertyController::class, 'update']);
    Route::delete('/properties/{code}', [PropertyController::class, 'destroy']);
    Route::post('/properties/{code}/sync/{integrationId}', [PropertyController::class, 'syncStatus']);

    // Funcionarios
    Route::get('/consultants', [ConsultantController::class, 'index']);
    Route::get('/consultants/{consultant}', [ConsultantController::class, 'show']);

    // Portales
    Route::prefix('portals')->group(function () {
        Route::get('/mercadolibre/authorize', [MercadoLibreController::class, 'redirect']);
        Route::post('/properties/{code}/mercadolibre/publish', [MercadoLibreController::class, 'publish']);
        Route::post('/properties/{code}/mercadolibre/update', [MercadoLibreController::class, 'update']);
        Route::post('/properties/{code}/mercadolibre/pause', [MercadoLibreController::class, 'pause']);
        Route::post('/properties/{code}/mercadolibre/verify', [MercadoLibreController::class, 'verify']);

        Route::get('/fincaraiz/client', [FincaraizController::class, 'clientInfo']);
        Route::get('/errors', [PortalErrorController::class, 'index']);
        Route::post('/properties/{code}/fincaraiz/publish', [FincaraizController::class, 'publish']);
        Route::post('/properties/{code}/fincaraiz/update', [FincaraizController::class, 'update']);
        Route::post('/properties/{code}/fincaraiz/pause', [FincaraizController::class, 'pause']);

        Route::post('/ciencuadras/login', [CiencuadrasController::class, 'login']);
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

        Route::post('/google/generate', [XmlController::class, 'generateGoogle']);
    });
});

// Callbacks y webhooks (sin auth, validados por su propio mecanismo)
Route::get('/portals/mercadolibre/callback', [MercadoLibreController::class, 'callback'])->name('ml.callback');
Route::post('/portals/mercadolibre/webhook', [MercadoLibreController::class, 'webhook']);
Route::get('/portals/mercadolibre/webhook', [MercadoLibreController::class, 'webhook']);
