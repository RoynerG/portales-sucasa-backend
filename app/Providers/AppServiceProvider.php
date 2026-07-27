<?php

namespace App\Providers;

use App\Services\Portals\CiencuadrasClient;
use App\Services\Portals\FincaraizClient;
use App\Services\Portals\GoogleSitemapGenerator;
use App\Services\Portals\MercadoLibreClient;
use App\Services\Portals\ProppitClient;
use GuzzleHttp\Client;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MercadoLibreClient::class, fn () => new MercadoLibreClient(new Client([
            'timeout' => 30,
            'http_errors' => false,
        ])));
        $this->app->singleton(FincaraizClient::class, fn () => new FincaraizClient(new Client([
            'timeout' => 30,
            'http_errors' => false,
        ])));
        $this->app->singleton(CiencuadrasClient::class, fn () => new CiencuadrasClient(new Client([
            'timeout' => 30,
            'http_errors' => false,
            'verify' => true,
        ])));
        $this->app->singleton(ProppitClient::class, fn () => new ProppitClient(new Client([
            'timeout' => 45,
            'http_errors' => true,
            'verify' => true,
        ])));
        $this->app->singleton(GoogleSitemapGenerator::class);
    }

    public function boot(): void
    {
        //
    }
}
