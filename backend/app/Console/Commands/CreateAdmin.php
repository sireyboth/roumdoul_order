<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Creates the platform admin (/admin) on a fresh server, without demo data.
 * Run again with the same email to reset that admin's password.
 */
class CreateAdmin extends Command
{
    protected $signature = 'admin:create
        {--email= : Login email}
        {--name= : Display name}
        {--password= : At least 8 characters; leave out to type it hidden}';

    protected $description = 'Create a platform admin, or reset the password of an existing one';

    public function handle(): int
    {
        $email = $this->option('email') ?? $this->ask('Email');
        $name = $this->option('name') ?? $this->ask('Name', 'Admin');
        $password = $this->option('password');

        if ($password === null) {
            $password = $this->secret('Password (at least 8 characters)');
            if ($password !== $this->secret('Type the password again')) {
                $this->error('The passwords do not match.');

                return self::INVALID;
            }
        }

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $password],
            ['email' => 'required|email|max:255', 'name' => 'required|string|max:255', 'password' => 'required|string|min:8'],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::INVALID;
        }

        $user = User::query()->firstOrNew(['email' => strtolower($email)]);
        $isNew = ! $user->exists;
        $user->fill([
            'name' => $isNew ? $name : ($this->option('name') ?? $user->name),
            'password' => $password,
            'is_platform_admin' => true,
            'is_active' => true,
        ])->save();

        $this->info($isNew
            ? "Platform admin created: {$user->email}. Sign in at /admin."
            : "Updated {$user->email}: password reset, platform admin, active.");

        return self::SUCCESS;
    }
}
