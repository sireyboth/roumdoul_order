<?php

namespace Database\Seeders;

use App\Enums\CompanyStatus;
use App\Enums\Station;
use App\Models\Category;
use App\Models\Company;
use App\Models\DiningTable;
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

        $company->update(['status' => CompanyStatus::Active, 'trial_ends_at' => null]);

        // Manager PIN for approving discounts, voids and refunds on the cashier screen.
        $company->memberships()->where('user_id', $owner->id)->update(['pin_hash' => bcrypt('1234')]);

        $branch = $company->branches()->first();
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

        $this->item($company, $coffee, 'កាហ្វេទឹកដោះគោទឹកកក', 'Iced Khmer coffee', 150, Station::Bar, [$size, $sugar, $ice]);
        $this->item($company, $coffee, 'កាហ្វេឡាតេទឹកកក', 'Iced latte', 225, Station::Bar, [$size, $sugar, $ice, $extras]);
        $this->item($company, $coffee, 'អាមេរិកាណូក្តៅ', 'Hot Americano', 175, Station::Bar, [$size, $sugar, $extras]);
        $this->item($company, $tea, 'តែទឹកដោះគោពពុះ', 'Bubble milk tea', 200, Station::Bar, [$size, $sugar, $ice]);
        $this->item($company, $tea, 'តែក្រូចឆ្មា', 'Iced lemon tea', 150, Station::Bar, [$size, $sugar, $ice]);
        $this->item($company, $food, 'បាយឆា', 'Fried rice', 300, Station::Kitchen, [$spice]);
        $this->item($company, $food, 'នំបញ្ចុក', 'Khmer rice noodles', 250, Station::Kitchen, []);
        $this->item($company, $food, 'នំប៉័ងសាច់', 'Num pang (baguette)', 200, Station::Kitchen, [$spice]);

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
    private function item(Company $company, Category $category, string $km, string $en, int $price, Station $station, array $groups): void
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
    }
}
