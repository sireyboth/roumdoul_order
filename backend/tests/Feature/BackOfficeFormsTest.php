<?php

namespace Tests\Feature;

use App\Filament\App\Pages\Tenancy\EditCompanyProfile;
use App\Filament\App\Pages\Tenancy\RegisterCompany;
use App\Filament\App\Resources\Branches\Pages\CreateBranch;
use App\Filament\App\Resources\Branches\Pages\EditBranch;
use App\Filament\App\Resources\Branches\RelationManagers\AreasRelationManager;
use App\Filament\App\Resources\Branches\RelationManagers\MenuAvailabilityRelationManager;
use App\Filament\App\Resources\Categories\Pages\ListCategories;
use App\Filament\App\Resources\DiningTables\Pages\ListDiningTables;
use App\Filament\App\Resources\MenuItems\Pages\CreateMenuItem;
use App\Filament\App\Resources\MenuItems\Pages\EditMenuItem;
use App\Filament\App\Resources\OptionGroups\Pages\ListOptionGroups;
use App\Filament\App\Resources\Staff\Pages\ListStaff;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\DiningTable;
use App\Models\MenuItem;
use App\Models\OptionGroup;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Drives the real back-office screens the way an owner would. */
class BackOfficeFormsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->company = Company::query()->where('slug', 'demo-cafe')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@roumdoul.test')->firstOrFail();

        $this->actingAs($this->owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
        Filament::bootCurrentPanel();
    }

    public function test_create_pages_render(): void
    {
        foreach (['/branches/create', '/menu-items/create'] as $path) {
            $this->get('/app/demo-cafe'.$path)->assertOk();
        }

        $item = $this->company->menuItems()->firstOrFail();
        $this->get("/app/demo-cafe/menu-items/{$item->id}/edit")->assertOk();
    }

    public function test_owner_creates_a_menu_item_with_options_and_price_in_dollars(): void
    {
        $category = $this->company->categories()->firstOrFail();
        $size = OptionGroup::query()->where('name_en', 'Size')->firstOrFail();

        Livewire::test(CreateMenuItem::class)
            ->fillForm([
                'name_km' => 'កាពូឈីណូ',
                'name_en' => 'Cappuccino',
                'category_id' => $category->id,
                'price' => '2.75',
                'station' => 'bar',
                'optionGroups' => [$size->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $item = MenuItem::query()->where('name_en', 'Cappuccino')->firstOrFail();
        $this->assertSame(275, $item->price);
        $this->assertSame($this->company->id, $item->company_id);
        $this->assertSame([$size->id], $item->optionGroups->pluck('id')->all());
        $this->assertSame(1, $item->branchSettings()->count(), 'Available in the branch automatically');

        Livewire::test(EditMenuItem::class, ['record' => $item->id])
            ->assertFormSet(['price' => 2.75])
            ->fillForm(['price' => '3'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(300, $item->fresh()->price);
    }

    public function test_category_and_option_group_modals_work(): void
    {
        Livewire::test(ListCategories::class)
            ->callAction('create', ['name_km' => 'នំ', 'name_en' => 'Cakes'])
            ->assertHasNoActionErrors();
        $this->assertTrue(Category::query()->where('name_en', 'Cakes')->where('company_id', $this->company->id)->exists());

        Livewire::test(ListOptionGroups::class)
            ->callAction('create', [
                'name_km' => 'ទឹកដោះគោ',
                'name_en' => 'Milk',
                'min_select' => 1,
                'max_select' => 1,
                'options' => [
                    ['name_km' => 'ទឹកដោះគោស្រស់', 'name_en' => 'Fresh milk', 'price_delta' => '0', 'is_default' => true, 'is_active' => true],
                    ['name_km' => 'ទឹកដោះគោអូត', 'name_en' => 'Oat milk', 'price_delta' => '0.60', 'is_default' => false, 'is_active' => true],
                ],
            ])
            ->assertHasNoActionErrors();

        $group = OptionGroup::query()->where('name_en', 'Milk')->firstOrFail();
        $this->assertSame([0, 60], $group->options->pluck('price_delta')->all());
        $this->assertSame([$this->company->id], $group->options->pluck('company_id')->unique()->values()->all());
    }

    public function test_branch_create_edit_areas_and_menu_availability(): void
    {
        Livewire::test(CreateBranch::class)
            ->fillForm(['name' => 'Toul Kork', 'code' => 'TK', 'day_ends_at' => '03:00'])
            ->call('create')
            ->assertHasNoFormErrors();

        $branch = Branch::query()->where('code', 'TK')->firstOrFail();
        $this->assertSame($this->company->menuItems()->count(), $branch->menuItems()->count());

        Livewire::test(AreasRelationManager::class, ['ownerRecord' => $branch, 'pageClass' => EditBranch::class])
            ->callTableAction('create', data: ['name' => 'Garden'])
            ->assertHasNoTableActionErrors();
        $this->assertSame($this->company->id, $branch->tableAreas()->firstOrFail()->company_id);

        $row = $branch->menuItems()->firstOrFail();
        Livewire::test(MenuAvailabilityRelationManager::class, ['ownerRecord' => $branch, 'pageClass' => EditBranch::class])
            ->assertCanSeeTableRecords([$row])
            ->callTableAction('soldOut', $row)
            ->assertHasNoTableActionErrors();
        $this->assertTrue($row->fresh()->isSoldOut());
    }

    public function test_add_many_tables_and_qr_actions(): void
    {
        $branch = $this->company->branches()->firstOrFail();

        Livewire::test(ListDiningTables::class)
            ->callAction('addMany', ['branch_id' => $branch->id, 'prefix' => 'B', 'from' => 1, 'to' => 3])
            ->assertHasNoActionErrors();

        $this->assertSame(3, DiningTable::query()->where('name', 'like', 'B%')->count());

        $table = DiningTable::query()->where('name', 'B1')->firstOrFail();
        $old = $table->qr_token;

        Livewire::test(ListDiningTables::class)
            ->mountTableAction('qr', $table)
            ->assertSee($table->customerUrl());

        Livewire::test(ListDiningTables::class)
            ->callTableAction('newQr', $table)
            ->assertHasNoTableActionErrors();

        $this->assertNotSame($old, $table->fresh()->qr_token);

        Livewire::test(ListDiningTables::class)
            ->callTableAction('download', $table)
            ->assertFileDownloaded();
    }

    public function test_add_staff_member(): void
    {
        Livewire::test(ListStaff::class)
            ->callAction('create', [
                'name' => 'Sokha',
                'email' => 'sokha@example.com',
                'password' => 'secret123',
                'role' => 'waiter',
                'is_active' => true,
            ])
            ->assertHasNoActionErrors();

        $user = User::query()->where('email', 'sokha@example.com')->firstOrFail();
        $this->assertSame('waiter', $user->roleIn($this->company)->value);
    }

    public function test_restaurant_settings_save_percentages_as_basis_points(): void
    {
        Livewire::test(EditCompanyProfile::class)
            ->fillForm(['vat_bp' => '10', 'service_charge_bp' => '2.5', 'khr_per_usd' => 4050])
            ->call('save')
            ->assertHasNoFormErrors();

        $company = $this->company->fresh();
        $this->assertSame(1000, $company->vat_bp);
        $this->assertSame(250, $company->service_charge_bp);
        $this->assertSame(4050, $company->khr_per_usd);
    }

    public function test_new_user_registers_a_restaurant(): void
    {
        $newOwner = User::factory()->create();
        $this->actingAs($newOwner);
        // /app/new is outside any restaurant, so no current tenant (as in the browser).
        Filament::setTenant(null, isQuiet: true);

        Livewire::test(RegisterCompany::class)
            ->fillForm(['name' => 'Brown Coffee Test', 'branch_name' => 'Riverside', 'currency' => 'USD'])
            ->call('register')
            ->assertHasNoFormErrors();

        $company = Company::query()->where('name', 'Brown Coffee Test')->firstOrFail();
        $this->assertSame('trial', $company->status->value);
        $this->assertSame('Riverside', $company->branches()->value('name'));
        $this->assertTrue($newOwner->canAccessTenant($company));
    }
}
