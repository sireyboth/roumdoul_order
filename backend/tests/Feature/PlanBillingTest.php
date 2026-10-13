<?php

namespace Tests\Feature;

use App\Enums\Feature;
use App\Enums\InvoiceStatus;
use App\Filament\Admin\Pages\PaymentSettings;
use App\Filament\Admin\Resources\PlanInvoices\Pages\ListPlanInvoices;
use App\Filament\App\Pages\Tenancy\RegisterCompany;
use App\Filament\App\Pages\YourPlan;
use App\Filament\App\Resources\Categories\Pages\ListCategories;
use App\Filament\App\Resources\DailySales\Pages\ListDailySales;
use App\Models\Category;
use App\Models\Company;
use App\Models\DiningTable;
use App\Models\MenuItem;
use App\Models\Plan;
use App\Models\PlanInvoice;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Ordering\OrderPlacer;
use App\Services\Subscriptions\PlanQuote;
use App\Services\Subscriptions\SubscriptionService;
use App\Services\TelegramNotifier;
use App\Support\Terms;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** Plan billing (upgrade / downgrade / renewals / read-only), feature locking, team alerts. docs/roadmap.md C1 + C2. */
class PlanBillingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->company = Company::query()->where('slug', 'demo-cafe')->firstOrFail();
        $this->company->update(['phone' => '012 345 678', 'contact_telegram' => '@democafe']);
        $this->owner = User::query()->where('email', 'owner@roumdoul.test')->firstOrFail();
        $this->admin = User::query()->where('email', 'admin@roumdoul.test')->firstOrFail();

        config(['services.telegram.bot_token' => 'test-token', 'services.telegram.platform_chat_id' => '-100team']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        Storage::fake('public');

        // 16 Oct, on Starter monthly, paid 1 Oct – 1 Nov (31 days).
        Carbon::setTestNow('2026-10-16 00:00:00');
        $this->onPlan('starter', 'monthly', '2026-11-01 00:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function onPlan(string $code, string $interval, string $endsAt, string $status = 'active'): Subscription
    {
        $plan = Plan::query()->where('code', $code)->firstOrFail();
        $this->company->subscriptions()->update(['status' => 'ended', 'ended_at' => now()]);
        $this->company->unsetRelation('subscription');

        return $this->company->subscriptions()->create([
            'plan_id' => $plan->id, 'status' => $status, 'interval' => $interval,
            'price_cents' => $plan->priceFor($interval), 'currency' => 'USD',
            'starts_at' => Subscription::addInterval(Carbon::parse($endsAt), $interval, -1), 'ends_at' => $endsAt,
        ]);
    }

    /** Livewire temporary uploads need the real clock. */
    private function withRealTime(callable $callback): void
    {
        $frozen = now()->copy();
        Carbon::setTestNow();
        $callback();
        Carbon::setTestNow($frozen);
    }

    private function plan(string $code): Plan
    {
        return Plan::query()->where('code', $code)->firstOrFail();
    }

    private function asOwner(): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->owner, 'web');
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company->fresh());
        Filament::bootCurrentPanel();
    }

    private function asAdmin(): void
    {
        $this->actingAs($this->admin);
        Filament::setTenant(null, isQuiet: true);
        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();
    }

    private function assertTeamTold(string ...$texts): void
    {
        Http::assertSent(function (Request $request) use ($texts) {
            if (($request['chat_id'] ?? null) !== '-100team') {
                return false;
            }

            foreach ($texts as $text) {
                if (! str_contains((string) $request['text'], $text)) {
                    return false;
                }
            }

            return true;
        });
    }

    public function test_upgrade_pays_the_difference_for_the_days_left_and_downgrade_waits(): void
    {
        $sub = $this->company->subscription()->with('plan')->first();

        // ($39 − $19) × 16/31 = $10.32, rounded down.
        $up = PlanQuote::for($sub, $this->plan('pro'), 'monthly');
        $this->assertSame(PlanQuote::UPGRADE, $up->kind);
        $this->assertSame(1032, $up->amount);
        $this->assertStringContainsString('16/31 days', $up->working);
        $this->assertSame('2026-11-01', $up->periodEndsAt->toDateString());

        // Monthly → yearly: a new year from today, minus the unused part of this month ($19 × 16/31 = $9.81).
        $yearly = PlanQuote::for($sub, $this->plan('pro'), 'yearly');
        $this->assertSame(39000 - 981, $yearly->amount);
        $this->assertSame('2027-10-16', $yearly->periodEndsAt->toDateString());

        $down = PlanQuote::for($this->onPlan('pro', 'monthly', '2026-11-01'), $this->plan('starter'), 'monthly');
        $this->assertSame(PlanQuote::DOWNGRADE, $down->kind);
        $this->assertSame(0, $down->amount);
        $this->assertSame('2026-11-01', $down->startsAt->toDateString());

        $trial = PlanQuote::for($this->onPlan('starter', 'monthly', '2026-10-30', 'trialing'), $this->plan('chain'), 'monthly');
        $this->assertSame(PlanQuote::TRIAL, $trial->kind);
        $this->assertSame(0, $trial->amount);
    }

    public function test_owner_asks_to_upgrade_sends_the_screenshot_and_the_team_confirms(): void
    {
        $this->asOwner();

        Livewire::test(YourPlan::class)
            ->callAction('changePlan', ['plan_id' => $this->plan('pro')->id, 'interval' => 'monthly', 'accept_terms' => true])
            ->assertHasNoActionErrors();

        $invoice = $this->company->planInvoices()->sole();
        $this->assertSame(PlanInvoice::KIND_UPGRADE, $invoice->kind);
        $this->assertSame(1032, $invoice->amount_cents);
        $this->assertSame(InvoiceStatus::Pending, $invoice->status);
        // Not upgraded until the money is confirmed.
        $this->assertSame('starter', $this->company->fresh()->subscription->plan->code);
        $this->assertTeamTold('Upgrade requested', 'Demo Café', '012 345 678', '@democafe', '$10.32', 'INV-');

        $this->withRealTime(fn () => Livewire::test(YourPlan::class)
            ->callAction('paid', ['invoice_id' => $invoice->id, 'proof_path' => UploadedFile::fake()->image('aba.png', 300, 600), 'note' => 'From Sokha'])
            ->assertHasNoActionErrors());
        $this->assertNotNull($invoice->fresh()->proof_sent_at);
        $this->assertTeamTold('Payment sent', 'From Sokha');

        $this->asAdmin();
        Livewire::test(ListPlanInvoices::class)
            ->callTableAction('confirm', $invoice, ['method' => 'aba', 'reference' => 'ABA-778899'])
            ->assertHasNoTableActionErrors();

        $company = $this->company->fresh();
        $sub = $company->subscription;
        $this->assertSame('pro', $sub->plan->code);
        $this->assertSame(3900, $sub->price_cents);
        // Same renewal date: the difference paid up to 1 Nov.
        $this->assertSame('2026-11-01', $sub->ends_at->toDateString());
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        // History kept: the Starter row is ended, not changed.
        $this->assertSame(1, $company->subscriptions()->whereNotNull('ended_at')->whereHas('plan', fn ($q) => $q->where('code', 'starter'))->where('status', 'ended')->count());
        $this->assertTeamTold('Payment confirmed', 'ABA-778899', 'Now on: Pro');
    }

    public function test_team_can_upgrade_a_restaurant_that_already_paid_in_one_step(): void
    {
        $this->asAdmin();

        app(SubscriptionService::class)->change($this->company, $this->plan('chain'), 'monthly', $this->admin, ['method' => 'cash']);

        $this->assertSame('chain', $this->company->fresh()->subscription->plan->code);
        $this->assertSame(InvoiceStatus::Paid, $this->company->planInvoices()->sole()->status);
    }

    public function test_downgrade_starts_at_renewal_with_the_new_price_and_can_be_cancelled(): void
    {
        $this->onPlan('pro', 'monthly', '2026-11-01');
        $this->asOwner();

        Livewire::test(YourPlan::class)
            ->callAction('changePlan', ['plan_id' => $this->plan('starter')->id, 'interval' => 'monthly', 'accept_terms' => true])
            ->assertHasNoActionErrors();

        $sub = $this->company->fresh()->subscription;
        $this->assertSame('pro', $sub->plan->code);
        $this->assertSame('starter', $sub->scheduledPlan->code);
        $this->assertSame(0, $this->company->planInvoices()->count());
        $this->assertTeamTold('Downgrade scheduled');

        // Changed their mind, then downgraded again.
        Livewire::test(YourPlan::class)->callAction('cancelDowngrade');
        $this->assertNull($this->company->fresh()->subscription->scheduled_plan_id);
        app(SubscriptionService::class)->change($this->company->fresh(), $this->plan('starter'), 'monthly', $this->owner);

        // 3 days before the renewal: the invoice is for Starter, from 1 Nov.
        Carbon::setTestNow('2026-10-29 10:00:00');
        $this->assertSame(1, app(SubscriptionService::class)->issueDueInvoices());
        $this->assertSame(0, app(SubscriptionService::class)->issueDueInvoices(), 'made only once');
        $invoice = $this->company->planInvoices()->sole();
        $this->assertSame(PlanInvoice::KIND_RENEWAL, $invoice->kind);
        $this->assertSame('starter', $invoice->plan->code);
        $this->assertSame(1900, $invoice->amount_cents);
        $this->assertSame('2026-11-01', $invoice->due_at->toDateString());
        $this->assertTeamTold('Invoice to collect', '$19.00');

        app(SubscriptionService::class)->confirm($invoice, $this->admin, 'aba');

        $sub = $this->company->fresh()->subscription;
        $this->assertSame('starter', $sub->plan->code);
        $this->assertSame('2026-11-01', $sub->starts_at->toDateString());
        $this->assertSame('2026-12-01', $sub->ends_at->toDateString());
    }

    public function test_late_payment_makes_the_back_office_read_only_but_the_shop_keeps_working(): void
    {
        Carbon::setTestNow('2026-10-29 10:00:00');
        app(SubscriptionService::class)->issueDueInvoices();
        $invoice = $this->company->planInvoices()->sole();

        // Due 1 Nov + 3 days grace: still fine on 3 Nov, read-only on 5 Nov.
        Carbon::setTestNow('2026-11-03 10:00:00');
        $this->assertFalse($this->company->fresh()->isReadOnly());
        $this->assertSame(0, app(SubscriptionService::class)->flagOverdue());

        Carbon::setTestNow('2026-11-05 10:00:00');
        $this->assertTrue($this->company->fresh()->isReadOnly());
        $this->assertSame(1, app(SubscriptionService::class)->flagOverdue());
        $this->assertSame(0, app(SubscriptionService::class)->flagOverdue(), 'told once');
        $this->assertTeamTold('read-only', '012 345 678');

        $this->asOwner();
        Livewire::test(ListCategories::class)->callAction('create', ['name_km' => 'នំ', 'name_en' => 'Cakes']);
        $this->assertFalse(Category::query()->where('name_en', 'Cakes')->exists());
        $this->get('/app/demo-cafe')->assertOk()->assertSee('Read-only');

        // Paying still works while read-only.
        $this->withRealTime(fn () => Livewire::test(YourPlan::class)
            ->callAction('paid', ['invoice_id' => $invoice->id, 'proof_path' => UploadedFile::fake()->image('aba.png', 300, 600)])
            ->assertHasNoActionErrors());
        $this->assertNotNull($invoice->fresh()->proof_sent_at);

        // Guests can still order.
        $table = DiningTable::query()->where('company_id', $this->company->id)->where('name', 'T1')->firstOrFail();
        $this->getJson('/api/public/tables/'.$table->qr_token)->assertOk();

        app(SubscriptionService::class)->confirm($invoice, $this->admin, 'aba');
        $this->assertFalse($this->company->fresh()->isReadOnly());
        $this->assertSame('2026-12-01', $this->company->fresh()->subscription->ends_at->toDateString());
    }

    public function test_features_follow_the_plan_live(): void
    {
        // Pay per order includes no features.
        $this->onPlan('per_order', 'monthly', '2026-11-01');
        $this->company->update(['telegram_chat_id' => '-100shop']);
        $table = DiningTable::query()->where('company_id', $this->company->id)->where('name', 'T1')->firstOrFail();
        $item = MenuItem::query()->where('company_id', $this->company->id)->whereHas('suggestions')->firstOrFail();
        $rice = MenuItem::query()->where('name_en', 'Fried rice')->with('optionGroups.options')->firstOrFail();
        $order = app(OrderPlacer::class)->place($table, [['menu_item_id' => $rice->id, 'quantity' => 1, 'option_ids' => $rice->optionGroups->flatMap->options->where('name_en', 'Not spicy')->pluck('id')->all()]], idempotencyKey: (string) Str::uuid());
        $token = $this->postJson('/api/staff/login', ['email' => 'cashier@roumdoul.test', 'password' => 'password'])->json('data.token');

        // Customers: no "Goes well with". Staff: no printing. Shop's own Telegram: nothing sent.
        $menu = collect($this->getJson('/api/public/tables/'.$table->qr_token)->json('data.categories'))->pluck('items')->flatten(1);
        $this->assertSame([], $menu->firstWhere('id', $item->id)['suggestions']);
        $this->withToken($token)->getJson("/api/staff/orders/{$order->id}/ticket")
            ->assertForbidden()->assertJsonPath('code', 'feature_not_in_plan');
        $this->withToken($token)->getJson("/api/staff/branches/{$table->branch_id}/board")
            ->assertJsonPath('data.settings.printing', false)->assertJsonPath('data.settings.auto_print_kitchen', false);
        $this->withToken($token)->getJson("/api/staff/branches/{$table->branch_id}/tables")->assertJsonPath('data.printing', false);
        app(TelegramNotifier::class)->newOrder($order);
        Http::assertNotSent(fn (Request $request) => ($request['chat_id'] ?? null) === '-100shop');

        // Owner: export locked with a hint.
        $this->asOwner();
        Livewire::test(ListDailySales::class)->assertActionDisabled('export_orders');

        // Back on Pro: everything is there again at once, nothing was deleted.
        $this->onPlan('pro', 'monthly', '2026-11-01');
        $menu = collect($this->getJson('/api/public/tables/'.$table->qr_token)->json('data.categories'))->pluck('items')->flatten(1);
        $this->assertNotSame([], $menu->firstWhere('id', $item->id)['suggestions']);
        $this->withToken($token)->getJson("/api/staff/orders/{$order->id}/ticket")->assertOk();
        $this->withToken($token)->getJson("/api/staff/branches/{$table->branch_id}/board")->assertJsonPath('data.settings.printing', true);
    }

    public function test_every_built_feature_is_checked_in_the_code_and_no_planned_one_is(): void
    {
        $code = collect(File::allFiles(app_path()))
            ->reject(fn ($file) => str_ends_with($file->getPathname(), 'Enums'.DIRECTORY_SEPARATOR.'Feature.php'))
            ->map(fn ($file) => $file->getContents())
            ->implode("\n");

        foreach (Feature::cases() as $feature) {
            $used = str_contains($code, 'Feature::'.$feature->name.')') || str_contains($code, 'Feature::'.$feature->name.',');

            $this->assertSame($feature->isBuilt(), $used, $feature->isBuilt()
                ? "Feature::{$feature->name} is marked built but nothing checks it: lock it by plan (FeatureGate / hasFeature)."
                : "Feature::{$feature->name} is used in the code but still marked \"Coming soon\": mark it built in App\\Enums\\Feature::isBuilt().");
        }
    }

    public function test_new_restaurant_is_announced_to_the_team(): void
    {
        Carbon::setTestNow();
        $newOwner = User::factory()->create(['name' => 'Sokha']);
        $this->actingAs($newOwner);
        Filament::setCurrentPanel('app');
        Filament::setTenant(null, isQuiet: true);

        Livewire::test(RegisterCompany::class)
            ->fillForm([
                'name' => 'Riverside Coffee',
                'logo_path' => UploadedFile::fake()->image('logo.png', 200, 200),
                'phone' => '099 888 777',
                'contact_telegram' => '@riverside',
                'accept_terms' => true,
                'branch_name' => 'Riverside',
                'currency' => 'USD',
            ])
            ->call('register')
            ->assertHasNoFormErrors();

        $this->assertSame('@riverside', Company::query()->where('name', 'Riverside Coffee')->value('contact_telegram'));
        $this->assertTeamTold('New restaurant signed up', 'Riverside Coffee', '099 888 777', '@riverside', 'Sokha');
    }

    public function test_payment_settings_show_on_the_owners_plan_page_and_only_owners_see_it(): void
    {
        $this->asAdmin();
        Livewire::test(PaymentSettings::class)
            ->fillForm(['payment_account_name' => 'ROUMDOUL CO LTD', 'payment_account_number' => '000 123 456'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('000 123 456', PlatformSetting::get('payment_account_number'));

        app(SubscriptionService::class)->change($this->company, $this->plan('pro'), 'monthly', $this->owner);

        $this->asOwner();
        $this->get('/app/demo-cafe/your-plan')->assertOk()
            ->assertSee('ROUMDOUL CO LTD')->assertSee('000 123 456')->assertSee('$10.32');

        $kitchen = User::query()->where('email', 'kitchen@roumdoul.test')->firstOrFail();
        $this->actingAs($kitchen);
        $this->assertFalse(YourPlan::canAccess());
    }

    public function test_owner_sees_the_terms_again_and_must_agree_before_confirming(): void
    {
        $this->asOwner();
        $pro = $this->plan('pro');

        // The key terms of this exact change, right above Confirm.
        Livewire::test(YourPlan::class)
            ->mountAction('changePlan')
            ->fillForm(['plan_id' => $pro->id, 'interval' => 'monthly'])
            ->assertMountedActionModalSee(['Before you confirm', 'You pay $10.32 now', 'not refunded', 'read-only', 'Terms and conditions'])
            // Not ticked: nothing happens.
            ->callMountedAction()
            ->assertHasActionErrors(['accept_terms' => 'accepted']);
        $this->assertSame(0, $this->company->planInvoices()->count());
        $this->assertSame(0, $this->company->termsAcceptances()->count());

        Livewire::test(YourPlan::class)
            ->callAction('changePlan', ['plan_id' => $pro->id, 'interval' => 'monthly', 'accept_terms' => true])
            ->assertHasNoActionErrors();

        $accepted = $this->company->termsAcceptances()->sole();
        $this->assertSame(Terms::PLAN_CHANGE, $accepted->context);
        $this->assertSame(config('tok.terms_version'), $accepted->version);
        $this->assertSame($this->owner->id, $accepted->user_id);
        $this->assertStringContainsString('Starter → Pro (monthly), $10.32 now', $accepted->detail);
        $this->assertTeamTold('Upgrade requested', 'agreed to Terms');

        // Downgrade: the reminder says there is no refund.
        $this->onPlan('pro', 'monthly', '2026-11-01');
        Livewire::test(YourPlan::class)
            ->mountAction('changePlan')
            ->fillForm(['plan_id' => $this->plan('starter')->id, 'interval' => 'monthly'])
            ->assertMountedActionModalSee(['Starter starts on 1 Nov 2026', 'No refund for the rest of this period']);
    }

    public function test_signing_up_requires_agreeing_to_the_terms_and_is_recorded(): void
    {
        Carbon::setTestNow();
        $newOwner = User::factory()->create();
        $this->actingAs($newOwner);
        Filament::setCurrentPanel('app');
        Filament::setTenant(null, isQuiet: true);
        $form = [
            'name' => 'Terms Café',
            'logo_path' => UploadedFile::fake()->image('logo.png', 200, 200),
            'phone' => '099 111 222',
            'branch_name' => 'Main',
            'currency' => 'USD',
        ];

        Livewire::test(RegisterCompany::class)->fillForm($form)->call('register')
            ->assertHasFormErrors(['accept_terms' => 'accepted']);
        $this->assertFalse(Company::query()->where('name', 'Terms Café')->exists());

        Livewire::test(RegisterCompany::class)->fillForm([...$form, 'accept_terms' => true])->call('register')
            ->assertHasNoFormErrors();

        $company = Company::query()->where('name', 'Terms Café')->firstOrFail();
        $this->assertSame(Terms::SIGNUP, $company->termsAcceptances()->sole()->context);
    }

    public function test_terms_page_link_points_to_the_frontend(): void
    {
        config(['tok.frontend_url' => 'https://tok.roumdoul.com']);

        $this->assertSame('https://tok.roumdoul.com/terms', Terms::url());
    }
}
