<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $config = config('attendance.admin');
        if (! $config['email'] || ! $config['password']) {
            $this->command?->warn('ADMIN_EMAIL/ADMIN_PASSWORD kosong, super admin tidak dibuat.');

            return;
        }

        User::query()->firstOrCreate(
            ['email' => Str::lower($config['email'])],
            ['name' => $config['name'], 'password' => $config['password'], 'role' => 'super_admin', 'is_active' => true],
        );
    }
}
