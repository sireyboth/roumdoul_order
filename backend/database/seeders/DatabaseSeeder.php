<?php

namespace Database\Seeders;

use App\Enums\StaffRole;
use App\Models\Company;
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

        // Default login for Leng: platform admin and owner of the demo café (manager PIN 1234).
        $leng = User::query()->firstOrCreate(
            ['email' => 'leng@roumdoul.com'],
            ['name' => 'Leng', 'password' => 'password', 'is_platform_admin' => true],
        );
        $demo = Company::query()->where('slug', 'demo-cafe')->first();
        if ($demo && ! $demo->memberships()->where('user_id', $leng->id)->exists()) {
            $demo->memberships()->create([
                'user_id' => $leng->id,
                'role' => StaffRole::Owner->value,
                'is_active' => true,
                'pin_hash' => bcrypt('1234'),
            ]);
        }
        $this->command?->info('Default login (/admin, /app, /staff): leng@roumdoul.com / password (manager PIN 1234)');
    }
}
