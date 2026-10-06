<?php

namespace Tests\Feature;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\DiningTable;
use App\Models\MenuItem;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicMenuTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private DiningTable $table;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->company = Company::query()->where('slug', 'demo-cafe')->firstOrFail();
        $this->table = $this->company->diningTables()->where('name', 'T1')->firstOrFail();
    }

    private function menu(?string $token = null)
    {
        return $this->getJson('/api/public/tables/'.($token ?? $this->table->qr_token));
    }

    private function itemIds($response): array
    {
        return collect($response->json('data.categories'))->pluck('items')->flatten(1)->pluck('id')->all();
    }

    public function test_scanning_a_table_returns_its_branch_menu(): void
    {
        $response = $this->menu()->assertOk();

        $response->assertJsonPath('data.table.name', 'T1')
            ->assertJsonPath('data.table.area', 'Indoor')
            ->assertJsonPath('data.branch.name', 'BKK1')
            ->assertJsonPath('data.company.currency', 'USD')
            ->assertJsonCount(3, 'data.categories');

        $latte = collect($response->json('data.categories.0.items'))->firstWhere('name.en', 'Iced latte');
        $this->assertSame(225, $latte['price']);
        $this->assertCount(4, $latte['option_groups']);
        $this->assertSame('Size', $latte['option_groups'][0]['name']['en']);
    }

    public function test_unknown_or_malformed_token_is_not_found(): void
    {
        $this->menu('ThisTokenDoesNotExist1234')->assertNotFound();
        $this->getJson('/api/public/tables/1')->assertNotFound();
    }

    public function test_inactive_table_is_not_found(): void
    {
        $this->menu()->assertOk();

        $this->table->update(['is_active' => false]);

        $this->menu()->assertNotFound();
    }

    public function test_suspended_restaurant_and_expired_trial_cannot_take_orders(): void
    {
        $this->company->update(['status' => CompanyStatus::Suspended]);
        $this->menu()->assertNotFound();

        $this->company->update(['status' => CompanyStatus::Trial, 'trial_ends_at' => now()->subDay()]);
        $this->menu()->assertNotFound();

        $this->company->update(['trial_ends_at' => now()->addDay()]);
        $this->menu()->assertOk();
    }

    public function test_menu_changes_show_immediately_despite_cache(): void
    {
        $latte = MenuItem::query()->where('name_en', 'Iced latte')->firstOrFail();

        $this->menu()->assertOk(); // warm the cache

        $latte->update(['price' => 250]);

        $item = collect($this->menu()->json('data.categories.0.items'))->firstWhere('id', $latte->id);
        $this->assertSame(250, $item['price']);
    }

    public function test_branch_can_hide_items_override_prices_and_mark_sold_out(): void
    {
        $branchId = $this->table->branch_id;
        $latte = MenuItem::query()->where('name_en', 'Iced latte')->firstOrFail();
        $rice = MenuItem::query()->where('name_en', 'Fried rice')->firstOrFail();
        $tea = MenuItem::query()->where('name_en', 'Iced lemon tea')->firstOrFail();

        $this->menu()->assertOk(); // warm the cache

        $latte->branchSettings()->where('branch_id', $branchId)->firstOrFail()->update(['is_available' => false]);
        $rice->branchSettings()->where('branch_id', $branchId)->firstOrFail()->update(['price' => 350]);
        $tea->branchSettings()->where('branch_id', $branchId)->firstOrFail()->markSoldOut();

        $response = $this->menu()->assertOk();
        $items = collect($response->json('data.categories'))->pluck('items')->flatten(1)->keyBy('id');

        $this->assertFalse($items->has($latte->id), 'Hidden item must not be on the branch menu');
        $this->assertSame(350, $items[$rice->id]['price']);
        $this->assertNotNull($items[$tea->id]['sold_out_until']);
    }

    public function test_one_restaurants_token_never_shows_another_restaurants_menu(): void
    {
        $other = app(\App\Services\CompanyProvisioner::class)->create(
            ['name' => 'Other Place'],
            \App\Models\User::factory()->create(),
        );
        $otherBranch = $other->branches()->first();
        $otherCategory = $other->categories()->create(['name_km' => 'ផ្សេង', 'name_en' => 'Other']);
        $otherItem = $other->menuItems()->create([
            'category_id' => $otherCategory->id, 'name_km' => 'ផ្សេង', 'name_en' => 'Secret dish', 'price' => 999,
        ]);
        $otherTable = DiningTable::query()->create(['branch_id' => $otherBranch->id, 'name' => 'X1']);

        $mine = $this->itemIds($this->menu());
        $theirs = $this->itemIds($this->menu($otherTable->qr_token));

        $this->assertNotContains($otherItem->id, $mine);
        $this->assertSame([$otherItem->id], $theirs);
        $this->assertSame($other->id, $otherTable->company_id, 'Table takes its company from the branch');
    }

    public function test_new_qr_code_disables_the_old_one(): void
    {
        $old = $this->table->qr_token;
        $this->menu($old)->assertOk();

        $this->table->regenerateToken();

        $this->menu($old)->assertNotFound();
        $this->menu($this->table->fresh()->qr_token)->assertOk();
    }
}
