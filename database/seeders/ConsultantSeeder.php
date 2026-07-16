<?php

namespace Database\Seeders;

use App\Models\Consultant;
use App\Models\User;
use Illuminate\Database\Seeder;

class ConsultantSeeder extends Seeder
{
    public function run(): void
    {
        $asesores = [
            ['name' => 'Carlos Pérez',     'email' => 'carlos@sucasa.com',     'phone' => '+57 300 222 2222', 'whatsapp' => '+573002222222', 'position' => 'Asesor Senior', 'department' => 'Ventas',    'properties_limit' => 30, 'featured_limit' => 8,  'license_number' => 'SC-001'],
            ['name' => 'Ana Martínez',     'email' => 'ana@sucasa.com',        'phone' => '+57 300 333 3333', 'whatsapp' => '+573003333333', 'position' => 'Asesor Junior', 'department' => 'Ventas',    'properties_limit' => 20, 'featured_limit' => 5,  'license_number' => 'SC-002'],
            ['name' => 'Luis Rodríguez',   'email' => 'luis@sucasa.com',       'phone' => '+57 300 444 4444', 'whatsapp' => '+573004444444', 'position' => 'Asesor Senior', 'department' => 'Arriendos', 'properties_limit' => 40, 'featured_limit' => 10, 'license_number' => 'SC-003'],
            ['name' => 'María González',   'email' => 'maria@sucasa.com',      'phone' => '+57 300 555 5555', 'whatsapp' => '+573005555555', 'position' => 'Asesor Junior', 'department' => 'Arriendos', 'properties_limit' => 25, 'featured_limit' => 5,  'license_number' => 'SC-004'],
            ['name' => 'Jorge Hernández',  'email' => 'jorge@sucasa.com',      'phone' => '+57 300 666 6666', 'whatsapp' => '+573006666666', 'position' => 'Director',     'department' => 'Comercial', 'properties_limit' => 50, 'featured_limit' => 15, 'license_number' => 'SC-005'],
        ];

        foreach ($asesores as $data) {
            $email = $data['email'];
            unset($data['email']);
            $user = User::where('email', $email)->first();
            if ($user) $data['user_id'] = $user->id;
            Consultant::updateOrCreate(['name' => $data['name']], array_merge($data, ['active' => true]));
        }
    }
}
