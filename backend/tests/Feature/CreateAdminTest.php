<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_platform_admin(): void
    {
        $this->artisan('admin:create', ['--email' => 'Boss@Example.com', '--name' => 'Boss', '--password' => 'secret-123'])
            ->assertSuccessful();

        $user = User::query()->where('email', 'boss@example.com')->firstOrFail();
        $this->assertTrue($user->is_platform_admin);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('secret-123', $user->password));
    }

    public function test_running_again_resets_the_password_without_a_duplicate(): void
    {
        $this->artisan('admin:create', ['--email' => 'boss@example.com', '--name' => 'Boss', '--password' => 'secret-123']);
        $this->artisan('admin:create', ['--email' => 'boss@example.com', '--password' => 'another-456'])
            ->expectsQuestion('Name', 'Admin')
            ->assertSuccessful();

        $users = User::query()->where('email', 'boss@example.com')->get();
        $this->assertCount(1, $users);
        $this->assertSame('Boss', $users->first()->name);
        $this->assertTrue(Hash::check('another-456', $users->first()->password));
    }

    public function test_it_asks_for_the_password_hidden_and_refuses_a_short_one(): void
    {
        $this->artisan('admin:create', ['--email' => 'boss@example.com', '--name' => 'Boss'])
            ->expectsQuestion('Password (at least 8 characters)', 'short')
            ->expectsQuestion('Type the password again', 'short')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'boss@example.com']);
    }
}
