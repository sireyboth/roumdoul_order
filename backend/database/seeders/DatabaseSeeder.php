<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PlanSeeder::class);

        User::query()->firstOrCreate(
            ['email' => 'admin@roumdoul.test'],
            ['name' => 'Platform Admin', 'password' => 'password', 'is_platform_admin' => true],
        );
        $this->command?->info('Platform admin login (/admin): admin@roumdoul.test / password');

        $this->call(DemoSeeder::class);
    }
}
