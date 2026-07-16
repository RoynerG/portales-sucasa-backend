<?php

namespace App\Console\Commands;

use App\Services\Portals\GoogleSitemapGenerator;
use App\Services\Portals\ProppitFeedGenerator;
use Illuminate\Console\Command;

class GeneratePortalFeeds extends Command
{
    protected $signature = 'portals:generate-feeds {--portal=* : proprtit,google}';

    protected $description = 'Genera los feeds XML (Proppit, Google Sitemap) a partir de las propiedades activas.';

    public function handle(ProppitFeedGenerator $proppit, GoogleSitemapGenerator $google): int
    {
        $portals = $this->option('portal') ?: ['proppit', 'google'];

        foreach ($portals as $portal) {
            $this->info("Generando feed: {$portal}");
            $path = match ($portal) {
                'proppit' => $proppit->writeToFile(),
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
