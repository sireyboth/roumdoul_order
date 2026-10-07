<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Branches\Pages\ListBranches;
use App\Models\Branch;
use App\Models\Company;
use App\Models\DiningTable;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use App\Services\Ordering\LocationCheck;
use App\Support\MapLink;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** QR orders only from inside the shop: the phone's location must be near the branch. */
class LocationCheckTest extends TestCase
{
    use RefreshDatabase;

    // Demo shop point (BKK1) and places around it.
    private const SHOP = ['lat' => 11.5564, 'lng' => 104.9282];

    private const AT_TABLE = ['lat' => 11.5565, 'lng' => 104.9283, 'accuracy' => 20];   // ~15 m

    private const FAR_HOME = ['lat' => 11.5800, 'lng' => 104.9000, 'accuracy' => 15];   // ~4 km

    private Company $company;

    private Branch $branch;

    private DiningTable $table;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->company = Company::query()->where('slug', 'demo-cafe')->firstOrFail();
        $this->branch = $this->company->branches()->firstOrFail();
        $this->table = $this->company->diningTables()->where('name', 'T1')->firstOrFail();
        $this->branch->update([
            'latitude' => self::SHOP['lat'],
            'longitude' => self::SHOP['lng'],
            'require_location' => true,
            'order_radius_m' => 150,
        ]);
    }

    private function order(?array $location, ?string $key = null)
    {
        // Any dish without a required choice.
        $item = MenuItem::query()
            ->where('company_id', $this->company->id)
            ->whereDoesntHave('optionGroups', fn ($q) => $q->where('min_select', '>', 0))
            ->firstOrFail();

        return $this->postJson("/api/public/tables/{$this->table->qr_token}/orders", array_filter([
            'idempotency_key' => $key ?? (string) Str::uuid(),
            'items' => [['menu_item_id' => $item->id, 'quantity' => 1]],
            'location' => $location,
        ]));
    }

    public function test_menu_tells_the_phone_to_ask_for_location(): void
    {
        $this->getJson("/api/public/tables/{$this->table->qr_token}")
            ->assertOk()
            ->assertJsonPath('data.branch.location_required', true)
            // The shop's exact point is not handed out.
            ->assertJsonMissingPath('data.branch.latitude');
    }

    public function test_order_from_inside_the_shop_is_accepted_and_the_distance_kept(): void
    {
        $this->order(self::AT_TABLE)->assertCreated();

        $order = Order::query()->latest('id')->firstOrFail();
        $this->assertNotNull($order->customer_distance_m);
        $this->assertLessThan(50, $order->customer_distance_m);
    }

    public function test_order_from_home_or_without_location_is_refused(): void
    {
        $this->order(self::FAR_HOME)->assertStatus(422)->assertJsonPath('code', 'location_too_far');
        $this->order(null)->assertStatus(422)->assertJsonPath('code', 'location_required');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_poor_gps_accuracy_is_forgiven_but_only_up_to_a_limit(): void
    {
        // ~220 m away: outside 150 m, but the phone says it may be 100 m off.
        $this->order(['lat' => 11.5584, 'lng' => 104.9282, 'accuracy' => 100])->assertCreated();
        // Claiming 5 km inaccuracy does not open the door.
        $this->order(['lat' => 11.5600, 'lng' => 104.9282, 'accuracy' => 5000])->assertStatus(422);
    }

    public function test_a_retry_of_an_accepted_order_is_not_checked_again(): void
    {
        $key = (string) Str::uuid();
        $this->order(self::AT_TABLE, $key)->assertCreated();
        $this->order(null, $key)->assertOk();
        $this->assertSame(1, Order::query()->count());
    }

    public function test_calling_a_waiter_from_home_is_refused_too(): void
    {
        $url = "/api/public/tables/{$this->table->qr_token}/requests";

        $this->postJson($url, ['type' => 'waiter', 'location' => self::FAR_HOME])->assertStatus(422)->assertJsonPath('code', 'location_too_far');
        $this->postJson($url, ['type' => 'waiter', 'location' => self::AT_TABLE])->assertCreated();
    }

    public function test_switched_off_or_without_a_shop_point_nothing_is_required(): void
    {
        $this->branch->update(['require_location' => false]);
        $this->order(null)->assertCreated();

        $this->branch->update(['require_location' => true, 'latitude' => null, 'longitude' => null]);
        $this->assertFalse(LocationCheck::required($this->branch->fresh()));
        $this->order(null)->assertCreated();
    }

    public function test_map_links_are_read(): void
    {
        $this->assertSame(['lat' => 11.5564, 'lng' => 104.9282], MapLink::coordinates('11.5564, 104.9282'));
        $this->assertSame(['lat' => 11.5564, 'lng' => 104.9282], MapLink::coordinates('https://www.google.com/maps/@11.5564,104.9282,17z'));
        $this->assertSame(['lat' => 11.5561, 'lng' => 104.9287], MapLink::coordinates('https://www.google.com/maps/place/Cafe/@11.5564,104.9282,17z/data=!3m1!4b1!4m6!3m5!1s0x0:0x0!8m2!3d11.5561!4d104.9287'));
        $this->assertSame(['lat' => 11.5564, 'lng' => 104.9282], MapLink::coordinates('https://maps.google.com/?q=11.5564,104.9282'));
        $this->assertNull(MapLink::coordinates('not a place'));
        // Other short links are never fetched.
        $this->assertNull(MapLink::coordinates('https://evil.example/redirect'));
    }

    public function test_owner_sets_the_location_in_the_branch_modal(): void
    {
        $this->actingAs(User::query()->where('email', 'owner@roumdoul.test')->firstOrFail());
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
        $this->branch->update(['latitude' => null, 'longitude' => null, 'require_location' => false]);

        Livewire::test(ListBranches::class)
            ->mountTableAction('edit', $this->branch)
            ->setTableActionData(['map_link' => 'https://www.google.com/maps/@11.5564,104.9282,17z'])
            ->assertTableActionDataSet(['latitude' => 11.5564, 'longitude' => 104.9282])
            ->setTableActionData(['require_location' => true, 'order_radius_m' => 120])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $branch = $this->branch->fresh();
        $this->assertTrue(LocationCheck::required($branch));
        $this->assertSame(120, $branch->order_radius_m);

        // Switching it on without a point is not allowed.
        Livewire::test(ListBranches::class)
            ->callTableAction('edit', $branch, ['latitude' => null, 'longitude' => null, 'require_location' => true])
            ->assertHasTableActionErrors(['latitude', 'longitude']);
    }
}
