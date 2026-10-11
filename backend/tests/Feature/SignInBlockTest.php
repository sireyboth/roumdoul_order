<?php

namespace Tests\Feature;

use App\Enums\CompanyStatus;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Auth\Login;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Right password but not allowed in: the person is told why and who can fix it. */
class SignInBlockTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->company = Company::query()->where('slug', 'demo-cafe')->firstOrFail();
    }

    private function user(string $who): User
    {
        return User::query()->where('email', "{$who}@roumdoul.test")->firstOrFail();
    }

    private function backOfficeLogin(string $panel, string $email, string $password = 'password')
    {
        Filament::setCurrentPanel($panel);

        return Livewire::test(Login::class)
            ->fillForm(['email' => $email, 'password' => $password])
            ->call('authenticate');
    }

    private function staffLogin(string $email, string $password = 'password')
    {
        return $this->postJson('/api/staff/login', ['email' => $email, 'password' => $password]);
    }

    public function test_wrong_password_still_gets_the_plain_message(): void
    {
        $this->user('owner')->update(['is_active' => false]);

        $this->backOfficeLogin('app', 'owner@roumdoul.test', 'wrong')
            ->assertHasFormErrors(['email'])
            ->assertDontSee('blocked');

        $this->staffLogin('owner@roumdoul.test', 'wrong')
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Email or password is wrong.');
    }

    public function test_owner_on_the_admin_login_is_sent_to_app(): void
    {
        $this->backOfficeLogin('admin', 'owner@roumdoul.test')
            ->assertHasFormErrors(['email'])
            ->assertSee('Restaurant owners and managers sign in at /app');

        $this->assertGuest();
    }

    public function test_blocked_account_is_told_it_is_blocked(): void
    {
        $this->user('owner')->update(['is_active' => false]);

        $this->backOfficeLogin('app', 'owner@roumdoul.test')->assertSee('This account is blocked');
        $this->assertGuest();

        $this->staffLogin('owner@roumdoul.test')->assertStatus(422)
            ->assertJsonPath('errors.email.0', fn (string $m) => str_starts_with($m, 'This account is blocked'));
    }

    public function test_staff_account_on_the_back_office_is_sent_to_staff_screens(): void
    {
        $this->backOfficeLogin('app', 'kitchen@roumdoul.test')->assertSee('This account is for the staff screens');
        $this->assertGuest();
    }

    public function test_switched_off_staff_is_told_to_ask_the_owner(): void
    {
        $this->company->memberships()->where('user_id', $this->user('waiter')->id)->update(['is_active' => false]);

        $this->staffLogin('waiter@roumdoul.test')->assertStatus(422)
            ->assertJsonPath('errors.email.0', fn (string $m) => str_contains($m, 'switched off') && str_contains($m, 'Demo Café'));
    }

    public function test_suspended_or_expired_restaurant_is_explained(): void
    {
        $this->company->update(['status' => CompanyStatus::Suspended]);
        $this->staffLogin('waiter@roumdoul.test')
            ->assertJsonPath('errors.email.0', fn (string $m) => str_contains($m, 'Demo Café is paused'));

        $this->company->update(['status' => CompanyStatus::Trial, 'trial_ends_at' => now()->subDay()]);
        $this->staffLogin('waiter@roumdoul.test')
            ->assertJsonPath('errors.email.0', fn (string $m) => str_contains($m, 'free trial has ended'));
    }

    public function test_owner_of_a_cancelled_restaurant_is_told_it_is_closed(): void
    {
        $this->company->update(['status' => CompanyStatus::Cancelled]);

        $this->backOfficeLogin('app', 'owner@roumdoul.test')->assertSee('Demo Café is closed');
        $this->assertGuest();
    }

    public function test_new_person_without_a_restaurant_can_still_sign_in_to_register(): void
    {
        User::factory()->create(['email' => 'new@cafe.test', 'password' => 'password']);

        $this->backOfficeLogin('app', 'new@cafe.test')->assertHasNoFormErrors();
        $this->assertAuthenticated();
    }

    public function test_admin_blocks_and_unblocks_a_user(): void
    {
        $waiter = $this->user('waiter');
        $waiter->createToken('staff-screen', ['staff']);

        $this->actingAs($this->user('admin'));
        Filament::setCurrentPanel('admin');

        Livewire::test(ListUsers::class)->callTableAction('block', $waiter);

        $this->assertFalse($waiter->fresh()->is_active);
        $this->assertSame(0, $waiter->tokens()->count());

        Livewire::test(ListUsers::class)->callTableAction('unblock', $waiter);
        $this->assertTrue($waiter->fresh()->is_active);
    }

    public function test_admin_cannot_block_themselves(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');

        Livewire::test(ListUsers::class)->assertTableActionHidden('block', $admin);
    }
}
