<?php

namespace App\Console\Commands;

use App\Services\Portals\GoogleSitemapGenerator;
use Illuminate\Console\Command;

class GeneratePortalFeeds extends Command
{
    protected $signature = 'portals:generate-feeds {--portal=* : google}';

    protected $description = 'Genera feeds XML soportados a partir de las propiedades activas.';

    public function handle(GoogleSitemapGenerator $google): int
    {
        $portals = $this->option('portal') ?: ['google'];

        foreach ($portals as $portal) {
            $this->info("Generando feed: {$portal}");
            $path = match ($portal) {
                'google' => $google->writeToFile(),
                default => null,
            };
            if ($path) {
                $this->info("  -> {$path} (" . filesize($path) . " bytes)");
            } else {
                $this->warn("  -> Portal no soportado: {$portal}");
            }
        }

        return self::SUCCESS;
    }
}
