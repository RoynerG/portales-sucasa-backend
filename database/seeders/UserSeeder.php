<?php

namespace Database\Seeders;

use App\Models\Consultant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['email' => 'admin@sucasa.com'],
            [
                'name' => 'Administrador',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'active' => true,
                'phone' => '+57 300 000 0000',
            ]
        );

        $manager = User::updateOrCreate(
            ['email' => 'gerente@sucasa.com'],
            [
                'name' => 'Gerente Comercial',
                'password' => Hash::make('password'),
                'role' => 'manager',
                'active' => true,
                'phone' => '+57 300 111 1111',
            ]
        );

        User::updateOrCreate(
            ['email' => 'carlos@sucasa.com'],
            [
                'name' => 'Carlos Pérez',
                'password' => Hash::make('password'),
                'role' => 'agent',
                'active' => true,
                'phone' => '+57 300 222 2222',
            ]
        );
    }
}
