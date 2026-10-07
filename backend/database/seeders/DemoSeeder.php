<?php

namespace Database\Seeders;

use App\Enums\CompanyStatus;
use App\Enums\Station;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\DiningTable;
use App\Models\FloorPlan;
use App\Models\MenuItem;
use App\Models\OptionGroup;
use App\Models\User;
use App\Services\CompanyProvisioner;
use Illuminate\Database\Seeder;

/** A ready-to-try café: owner login, one branch, 6 tables, a small Khmer/English menu. */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Company::query()->where('slug', 'demo-cafe')->exists()) {
            $this->command?->info('Demo café already exists, skipping.');

            return;
        }

        $owner = User::query()->firstOrCreate(
            ['email' => 'owner@roumdoul.test'],
            ['name' => 'Demo Owner', 'password' => 'password'],
        );

        $company = app(CompanyProvisioner::class)->create([
            'name' => 'Demo Café',
            'slug' => 'demo-cafe',
            'branch_name' => 'BKK1',
            'currency' => 'USD',
        ], $owner, 'pro');

        $company->update(['status' => CompanyStatus::Active, 'trial_ends_at' => null, 'tagline' => 'Coffee, tea & brunch']);

        // Manager PIN for approving discounts, voids and refunds on the cashier screen.
        $company->memberships()->where('user_id', $owner->id)->update(['pin_hash' => bcrypt('1234')]);

        $branch = $company->branches()->first();
        // A point in BKK1 for the "orders only from inside the shop" check. Left off so the
        // demo works from any PC; switch it on in Branches → Edit → Location to try it.
        $branch->update(['latitude' => 11.5564, 'longitude' => 104.9282, 'require_location' => false]);
        $indoor = $branch->tableAreas()->create(['company_id' => $company->id, 'name' => 'Indoor', 'sort_order' => 1]);
        $terrace = $branch->tableAreas()->create(['company_id' => $company->id, 'name' => 'Terrace', 'sort_order' => 2]);

        foreach (range(1, 6) as $n) {
            DiningTable::query()->create([
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'table_area_id' => $n <= 4 ? $indoor->id : $terrace->id,
                'name' => 'T'.$n,
                'seats' => $n <= 4 ? 4 : 2,
                'sort_order' => $n,
            ]);
        }

        $size = $this->group($company, 'ទំហំ', 'Size', 1, 1, [
            ['តូច', 'Small', 0, true],
            ['ធំ', 'Large', 50, false],
        ]);
        $sugar = $this->group($company, 'កម្រិតស្ករ', 'Sugar level', 1, 1, [
            ['គ្មានស្ករ', 'No sugar', 0, false],
            ['ស្ករ 50%', '50% sugar', 0, false],
            ['ស្ករធម្មតា', 'Normal sugar', 0, true],
        ]);
        $ice = $this->group($company, 'ទឹកកក', 'Ice', 1, 1, [
            ['ទឹកកកតិច', 'Less ice', 0, false],
            ['ទឹកកកធម្មតា', 'Normal ice', 0, true],
        ]);
        $extras = $this->group($company, 'បន្ថែម', 'Extras', 0, 2, [
            ['Espresso shot', 'Extra shot', 50, false],
            ['ពពុះទឹកដោះគោ', 'Milk foam', 30, false],
        ]);
        $spice = $this->group($company, 'កម្រិតហឹរ', 'Spice level', 1, 1, [
            ['មិនហឹរ', 'Not spicy', 0, true],
            ['ហឹរបន្តិច', 'A little spicy', 0, false],
            ['ហឹរខ្លាំង', 'Very spicy', 0, false],
        ]);

        $coffee = $this->category($company, 'កាហ្វេ', 'Coffee', 1);
        $tea = $this->category($company, 'តែ', 'Tea', 2);
        $food = $this->category($company, 'ម្ហូប', 'Food', 3);

        $khmerCoffee = $this->item($company, $coffee, 'កាហ្វេទឹកដោះគោទឹកកក', 'Iced Khmer coffee', 150, Station::Bar, [$size, $sugar, $ice]);
        $latte = $this->item($company, $coffee, 'កាហ្វេឡាតេទឹកកក', 'Iced latte', 225, Station::Bar, [$size, $sugar, $ice, $extras]);
        $americano = $this->item($company, $coffee, 'អាមេរិកាណូក្តៅ', 'Hot Americano', 175, Station::Bar, [$size, $sugar, $extras]);
        $this->item($company, $tea, 'តែទឹកដោះគោពពុះ', 'Bubble milk tea', 200, Station::Bar, [$size, $sugar, $ice]);
        $lemonTea = $this->item($company, $tea, 'តែក្រូចឆ្មា', 'Iced lemon tea', 150, Station::Bar, [$size, $sugar, $ice]);
        $friedRice = $this->item($company, $food, 'បាយឆា', 'Fried rice', 300, Station::Kitchen, [$spice]);
        $this->item($company, $food, 'នំបញ្ចុក', 'Khmer rice noodles', 250, Station::Kitchen, []);
        $numPang = $this->item($company, $food, 'នំប៉័ងសាច់', 'Num pang (baguette)', 200, Station::Kitchen, [$spice]);

        // "Goes well with" pop-up: a bite with a coffee, a drink with food.
        $this->suggest($khmerCoffee, [$numPang]);
        $this->suggest($latte, [$numPang]);
        $this->suggest($americano, [$numPang]);
        $this->suggest($numPang, [$khmerCoffee, $latte]);
        $this->suggest($friedRice, [$lemonTea, $khmerCoffee]);

        $this->floorPlan($company, $branch);

        foreach (['kitchen' => 'Demo Kitchen', 'waiter' => 'Demo Waiter', 'cashier' => 'Demo Cashier'] as $role => $name) {
            $user = User::query()->firstOrCreate(
                ['email' => "{$role}@roumdoul.test"],
                ['name' => $name, 'password' => 'password'],
            );
            $company->memberships()->create(['user_id' => $user->id, 'role' => $role, 'is_active' => true]);
        }

        $this->command?->info('Demo café ready. Owner login: owner@roumdoul.test / password (manager PIN 1234)');
        $this->command?->info('Staff screens (/staff): kitchen@, waiter@, cashier@roumdoul.test / password');
        $this->command?->info('Customer menu links (start the Next.js site first):');

        foreach ($branch->diningTables()->get() as $table) {
            $this->command?->line("  {$table->name}: ".$table->customerUrl());
        }
    }

    private function category(Company $company, string $km, string $en, int $sort): Category
    {
        return Category::query()->create([
            'company_id' => $company->id, 'name_km' => $km, 'name_en' => $en, 'sort_order' => $sort,
        ]);
    }

    /** @param array<int, array{0:string,1:string,2:int,3:bool}> $options */
    private function group(Company $company, string $km, string $en, int $min, int $max, array $options): OptionGroup
    {
        $group = OptionGroup::query()->create([
            'company_id' => $company->id, 'name_km' => $km, 'name_en' => $en, 'min_select' => $min, 'max_select' => $max,
        ]);

        foreach ($options as $i => [$okm, $oen, $delta, $default]) {
            $group->options()->create([
                'company_id' => $company->id, 'name_km' => $okm, 'name_en' => $oen,
                'price_delta' => $delta, 'is_default' => $default, 'sort_order' => $i,
            ]);
        }

        return $group;
    }

    /** @param array<int, OptionGroup> $groups */
    private function item(Company $company, Category $category, string $km, string $en, int $price, Station $station, array $groups): MenuItem
    {
        static $sort = 0;

        $item = MenuItem::query()->create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'name_km' => $km,
            'name_en' => $en,
            'price' => $price,
            'station' => $station,
            'sort_order' => ++$sort,
        ]);

        foreach ($groups as $i => $group) {
            $item->optionGroups()->attach($group->id, ['sort_order' => $i]);
        }

        return $item;
    }

    /** @param array<int, MenuItem> $suggested */
    private function suggest(MenuItem $item, array $suggested): void
    {
        foreach ($suggested as $i => $other) {
            $item->suggestions()->attach($other->id, ['sort_order' => $i]);
        }

        $item->bumpMenuVersion();
    }

    /** BKK1's drawn layout for the staff screens: all six tables, the bar and the cashier. */
    private function floorPlan(Company $company, Branch $branch): void
    {
        $tables = $branch->diningTables()->pluck('id', 'name');
        $areas = $branch->diningTables()->whereIn('name', ['T1', 'T2', 'T3', 'T4', 'T5', 'T6'])->pluck('table_area_id')->unique();
        // One floor for all six tables: on their area if they share one, else the Main floor.
        $areaId = $areas->count() === 1 ? $areas->first() : null;

        $object = fn (string $id, string $kind, int $x, int $y, int $w, int $h, array $extra = []) => [
            'id' => $id, 'kind' => $kind, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'rotation' => 0,
            'table_id' => null, 'seats' => null, 'label' => null, 'color' => null, ...$extra,
        ];
        $table = fn (string $name, string $kind, int $x, int $y, int $w, int $h, int $seats) => $object(
            'seed-'.strtolower($name), $kind, $x, $y, $w, $h, ['table_id' => $tables[$name], 'seats' => $seats],
        );

        FloorPlan::query()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'table_area_id' => $areaId,
            'floor' => 'wood',
            'width' => 1200,
            'height' => 800,
            'objects' => [
                $object('seed-rug', 'rug', 380, 330, 340, 260, ['color' => '#c8a27a']),
                $object('seed-wall', 'wall', 0, 0, 1200, 20),
                $object('seed-plant-1', 'plant', 40, 40, 56, 56),
                $object('seed-plant-2', 'plant', 1110, 720, 56, 56),
                $object('seed-plant-3', 'plant', 40, 720, 56, 56),
                $table('T1', 'table_square', 160, 160, 80, 80, 4),
                $table('T2', 'table_square', 360, 160, 80, 80, 2),
                $table('T3', 'table_round', 560, 150, 100, 100, 4),
                $table('T4', 'table_long', 150, 420, 180, 80, 6),
                $table('T5', 'table_round', 450, 410, 100, 100, 4),
                $table('T6', 'table_square', 640, 420, 80, 80, 2),
                $object('seed-bar', 'bar_counter', 860, 120, 260, 70, ['label' => 'Bar']),
                $object('seed-stool-1', 'stool', 880, 210, 36, 36),
                $object('seed-stool-2', 'stool', 950, 210, 36, 36),
                $object('seed-stool-3', 'stool', 1020, 210, 36, 36),
                $object('seed-cashier', 'cashier', 900, 560, 170, 70, ['label' => 'Cashier']),
                $object('seed-door', 'door', 520, 760, 130, 40, ['label' => 'Entrance']),
            ],
        ]);

        // Keep the tables' seat counts the same as on the plan.
        foreach (['T1' => 4, 'T2' => 2, 'T3' => 4, 'T4' => 6, 'T5' => 4, 'T6' => 2] as $name => $seats) {
            DiningTable::query()->whereKey($tables[$name])->update(['seats' => $seats]);
        }
    }
}
