<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

class BrandingService
{
    public function payload(): array
    {
        return [
            'app_name' => config('app.name', 'Panel Sucasa'),
            'logo_url' => $this->systemImage('portal_logo_url', config('sources.branding.logo_fallback')),
            'favicon_url' => $this->systemImage('portal_favicon_url', config('sources.branding.favicon_fallback')),
            'palette' => [
                'primary_blue' => '#1B447D',
                'accent_orange' => '#F59120',
                'institutional_yellow' => '#F8CF4A',
                'dark_gray' => '#404041',
                'medium_gray' => '#635F5A',
                'light_gray' => '#A2A09A',
                'white' => '#FFFFFF',
            ],
        ];
    }

    public function systemImage(string $function, string $fallback): string
    {
        static $cache = [];

        if (array_key_exists($function, $cache)) {
            return $cache[$function];
        }

        if (config('sources.properties') !== 'wordpress') {
            return $cache[$function] = $fallback;
        }

        try {
            $row = DB::connection('wordpress')
                ->table('wp_jet_cct_confi_sistema')
                ->selectRaw('COALESCE(NULLIF(valor, ""), NULLIF(imagen, "")) AS image_url')
                ->where('funcion', $function)
                ->first();

            $url = trim((string) ($row->image_url ?? ''));

            return $cache[$function] = $url !== '' ? $url : $fallback;
        } catch (Throwable) {
            return $cache[$function] = $fallback;
        }
    }
}
