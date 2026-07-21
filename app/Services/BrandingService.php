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
            'logo_url' => $this->systemImage('portal_logo_url'),
            'favicon_url' => $this->systemImage('portal_favicon_url'),
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

    public function systemImage(string $function): string
    {
        static $cache = [];

        if (array_key_exists($function, $cache)) {
            return $cache[$function];
        }

        if (config('sources.properties') !== 'wordpress') {
            return $cache[$function] = '';
        }

        try {
            $row = DB::connection('wordpress')
                ->table('wp_jet_cct_confi_sistema')
                ->selectRaw('COALESCE(NULLIF(valor, ""), NULLIF(imagen, "")) AS image_url')
                ->where('funcion', $function)
                ->first();

            $url = trim((string) ($row->image_url ?? ''));

            return $cache[$function] = $url;
        } catch (Throwable) {
            return $cache[$function] = '';
        }
    }
}
