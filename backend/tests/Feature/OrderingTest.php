<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\DiningTable;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use App\Services\CompanyProvisioner;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderingTest extends TestCase
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

    private function item(string $en): MenuItem
    {
        return MenuItem::query()->where('name_en', $en)->with('optionGroups.options')->firstOrFail();
    }

    /** Option ids by English name, e.g. ['Large', 'Normal sugar']. */
    private function optionIds(MenuItem $item, array $names): array
    {
        return $item->optionGroups->flatMap->options->whereIn('name_en', $names)->pluck('id')->values()->all();
    }

    private function latteLine(array $optionNames = ['Large', 'Normal sugar', 'Normal ice', 'Extra shot'], int $qty = 2): array
    {
        $latte = $this->item('Iced latte');

        return ['menu_item_id' => $latte->id, 'quantity' => $qty, 'option_ids' => $this->optionIds($latte, $optionNames)];
    }

    private function order(array $items, ?string $key = null, ?string $token = null)
    {
        return $this->postJson('/api/public/tables/'.($token ?? $this->table->qr_token).'/orders', [
            'idempotency_key' => $key ?? (string) Str::uuid(),
            'items' => $items,
        ]);
    }

    public function test_customer_places_an_order_and_the_server_sets_the_prices(): void
    {
        $line = $this->latteLine() + ['unit_price' => 1, 'price' => 1]; // tampered prices are ignored

        $response = $this->order([$line])->assertCreated();

        // Latte 2.25 + Large 0.50 + Extra shot 0.50 = 3.25, × 2 = 6.50
        $response->assertJsonPath('data.number', 1)
            ->assertJsonPath('data.status', 'placed')
            ->assertJsonPath('data.subtotal', 650)
            ->assertJsonPath('data.items.0.unit_price', 325)
            ->assertJsonPath('data.items.0.quantity', 2);

        $order = Order::query()->firstOrFail();
        $this->assertSame($this->company->id, $order->company_id);
        $this->assertSame('bar', $order->items->first()->station);
        $this->assertCount(4, $order->items->first()->options);
    }

    public function test_same_tap_sent_twice_creates_one_order(): void
    {
        $key = (string) Str::uuid();

        $first = $this->order([$this->latteLine()], $key)->assertCreated();
        $second = $this->order([$this->latteLine()], $key)->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Order::query()->count());
    }

    public function test_orders_share_one_table_visit_and_numbers_count_up_per_branch(): void
    {
        $this->order([$this->latteLine()])->assertCreated();
        $this->order([$this->latteLine()])->assertCreated();

        $t2 = $this->company->diningTables()->where('name', 'T2')->firstOrFail();
        $this->order([$this->latteLine()], token: $t2->qr_token)->assertJsonPath('data.number', 3);

        $this->assertSame(1, Order::query()->where('dining_table_id', $this->table->id)->distinct()->count('table_session_id'));

        $this->getJson("/api/public/tables/{$this->table->qr_token}/session")
            ->assertOk()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonCount(2, 'data.orders')
            ->assertJsonPath('data.subtotal', 1300);
    }

    public function test_required_choices_are_enforced(): void
    {
        // Missing sugar level (required) on the latte
        $this->order([$this->latteLine(['Large', 'Normal ice'])])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0');

        // Three extras when the max is 2... the latte only has 2 extras, so pick 2 sizes instead
        $this->order([$this->latteLine(['Small', 'Large', 'Normal sugar', 'Normal ice'])])->assertStatus(422);
    }

    public function test_options_from_another_item_are_rejected(): void
    {
        $rice = $this->item('Fried rice');
        $line = $this->latteLine();
        $line['option_ids'][] = $this->optionIds($rice, ['Very spicy'])[0];

        $this->order([$line])->assertStatus(422);
    }

    public function test_sold_out_and_hidden_items_cannot_be_ordered(): void
    {
        $latte = $this->item('Iced latte');
        $setting = $latte->branchSettings()->where('branch_id', $this->table->branch_id)->firstOrFail();

        $setting->markSoldOut();
        $this->order([$this->latteLine()])->assertStatus(422);

        $setting->update(['sold_out_until' => null, 'is_available' => false]);
        $this->order([$this->latteLine()])->assertStatus(422);

        $setting->update(['is_available' => true]);
        $this->order([$this->latteLine()])->assertCreated();
    }

    public function test_branch_price_override_is_charged(): void
    {
        $rice = $this->item('Fried rice');
        $rice->branchSettings()->where('branch_id', $this->table->branch_id)->update(['price' => 350]);

        $this->order([['menu_item_id' => $rice->id, 'quantity' => 1, 'option_ids' => $this->optionIds($rice, ['Not spicy'])]])
            ->assertCreated()
            ->assertJsonPath('data.subtotal', 350);
    }

    public function test_another_restaurants_item_cannot_be_ordered_here(): void
    {
        $other = app(CompanyProvisioner::class)->create(['name' => 'Other'], User::factory()->create());
        $category = $other->categories()->create(['name_km' => 'x', 'name_en' => 'x']);
        $foreign = $other->menuItems()->create(['category_id' => $category->id, 'name_km' => 'x', 'name_en' => 'Foreign', 'price' => 1]);

        $this->order([['menu_item_id' => $foreign->id, 'quantity' => 1]])->assertStatus(422);
    }

    public function test_empty_or_invalid_orders_are_rejected(): void
    {
        $this->order([])->assertStatus(422);
        $this->order([['menu_item_id' => $this->item('Fried rice')->id, 'quantity' => 0]])->assertStatus(422);
        $this->postJson("/api/public/tables/{$this->table->qr_token}/orders", ['items' => [$this->latteLine()]])
            ->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
    }

    public function test_inactive_table_cannot_order(): void
    {
        $this->table->update(['is_active' => false]);

        $this->order([$this->latteLine()])->assertNotFound();
    }

    public function test_call_waiter_and_request_bill(): void
    {
        $this->order([$this->latteLine()])->assertCreated();

        $url = "/api/public/tables/{$this->table->qr_token}/requests";
        $this->postJson($url, ['type' => 'waiter'])->assertCreated();
        $this->postJson($url, ['type' => 'waiter'])->assertCreated(); // second tap: same request
        $this->postJson($url, ['type' => 'bill'])->assertCreated();
        $this->postJson($url, ['type' => 'dance'])->assertStatus(422);

        $this->assertSame(2, \App\Models\ServiceRequest::query()->count());

        $this->getJson("/api/public/tables/{$this->table->qr_token}/session")
            ->assertJsonPath('data.status', 'bill_requested')
            ->assertJsonCount(2, 'data.requests');
    }

    public function test_business_day_rolls_over_at_the_branch_cutoff(): void
    {
        $branch = $this->table->branch;
        $branch->update(['day_ends_at' => '04:00:00']);

        // 01:30 in Phnom Penh on the 8th belongs to the 7th
        $this->assertSame('2026-10-07', $branch->businessDate(\Carbon\Carbon::parse('2026-10-08 01:30', 'Asia/Phnom_Penh')));
        $this->assertSame('2026-10-08', $branch->businessDate(\Carbon\Carbon::parse('2026-10-08 04:30', 'Asia/Phnom_Penh')));
    }

    public function test_status_moves_follow_the_rules(): void
    {
        $this->order([$this->latteLine()])->assertCreated();
        $order = Order::query()->firstOrFail();

        $order->moveTo(OrderStatus::Accepted);
        $order->moveTo(OrderStatus::Ready);
        $this->assertNotNull($order->fresh()->ready_at);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $order->moveTo(OrderStatus::Placed);
    }
}
