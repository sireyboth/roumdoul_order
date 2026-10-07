<?php

namespace Tests\Feature;

use App\Filament\App\Pages\Tenancy\EditCompanyProfile;
use App\Filament\App\Pages\Tenancy\RegisterCompany;
use App\Filament\App\Resources\Branches\Pages\ListBranches;
use App\Filament\App\Resources\Branches\Pages\ViewBranch;
use App\Filament\App\Resources\Branches\RelationManagers\AreasRelationManager;
use App\Filament\App\Resources\Branches\RelationManagers\MenuAvailabilityRelationManager;
use App\Filament\App\Resources\Categories\Pages\ListCategories;
use App\Filament\App\Resources\DiningTables\Pages\ListDiningTables;
use App\Filament\App\Resources\MenuItems\Pages\ListMenuItems;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    public function test_create_and_edit_open_in_modals(): void
    {
        $item = $this->company->menuItems()->firstOrFail();
        $branch = $this->company->branches()->firstOrFail();

        // No separate create/edit pages any more: they are modals on the list.
        $this->get('/app/demo-cafe/menu-items/create')->assertNotFound();
        $this->get("/app/demo-cafe/menu-items/{$item->id}/edit")->assertNotFound();

        Livewire::test(ListMenuItems::class)->mountAction('create')->assertActionMounted('create');
        Livewire::test(ListMenuItems::class)
            ->mountTableAction('edit', $item)
            ->assertTableActionDataSet(['name_en' => $item->name_en]);
        Livewire::test(ListBranches::class)->mountAction('create')->assertActionMounted('create');
        Livewire::test(ListBranches::class)
            ->mountTableAction('edit', $branch)
            // "Business day ends" is a clock time: 04:00 must show as 04:00, not shifted to Phnom Penh time.
            ->assertTableActionDataSet(['name' => $branch->name, 'day_ends_at' => '04:00']);

        // The branch's areas and menu still have their own screen.
        $this->get("/app/demo-cafe/branches/{$branch->id}")->assertOk()->assertSee('Areas &amp; menu', false);
    }

    public function test_owner_creates_a_menu_item_with_options_and_price_in_dollars(): void
    {
        $category = $this->company->categories()->firstOrFail();
        $size = OptionGroup::query()->where('name_en', 'Size')->firstOrFail();

        Livewire::test(ListMenuItems::class)
            ->callAction('create', [
                'name_km' => 'កាពូឈីណូ',
                'name_en' => 'Cappuccino',
                'category_id' => $category->id,
                'price' => '2.75',
                'station' => 'bar',
                'optionGroups' => [$size->id],
            ])
            ->assertHasNoActionErrors();

        $item = MenuItem::query()->where('name_en', 'Cappuccino')->firstOrFail();
        $this->assertSame(275, $item->price);
        $this->assertSame($this->company->id, $item->company_id);
        $this->assertSame([$size->id], $item->optionGroups->pluck('id')->all());
        $this->assertSame(1, $item->branchSettings()->count(), 'Available in the branch automatically');

        Livewire::test(ListMenuItems::class)
            ->mountTableAction('edit', $item)
            ->assertTableActionDataSet(['price' => 2.75])
            ->setTableActionData(['price' => '3'])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

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
        Livewire::test(ListBranches::class)
            ->callAction('create', ['name' => 'Toul Kork', 'code' => 'TK', 'day_ends_at' => '03:00'])
            ->assertHasNoActionErrors();

        $branch = Branch::query()->where('code', 'TK')->firstOrFail();
        $this->assertSame($this->company->menuItems()->count(), $branch->menuItems()->count());

        Livewire::test(AreasRelationManager::class, ['ownerRecord' => $branch, 'pageClass' => ViewBranch::class])
            ->callTableAction('create', data: ['name' => 'Garden'])
            ->assertHasNoTableActionErrors();
        $this->assertSame($this->company->id, $branch->tableAreas()->firstOrFail()->company_id);

        $row = $branch->menuItems()->firstOrFail();
        Livewire::test(MenuAvailabilityRelationManager::class, ['ownerRecord' => $branch, 'pageClass' => ViewBranch::class])
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

    public function test_branch_printing_settings_save(): void
    {
        $branch = $this->company->branches()->firstOrFail();

        Livewire::test(ListBranches::class)
            ->callTableAction('edit', $branch, [
                'receipt_header' => 'VAT TIN K001-123456789',
                'receipt_footer' => 'សូមអរគុណ! Thank you!',
                'auto_print_kitchen' => true,
            ])
            ->assertHasNoTableActionErrors();

        $branch->refresh();
        $this->assertSame('VAT TIN K001-123456789', $branch->receipt_header);
        $this->assertSame('សូមអរគុណ! Thank you!', $branch->receipt_footer);
        $this->assertTrue($branch->auto_print_kitchen);
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

        Storage::fake('public');

        // The logo is required.
        Livewire::test(RegisterCompany::class)
            ->fillForm(['name' => 'No Logo Café', 'branch_name' => 'Riverside', 'currency' => 'USD'])
            ->call('register')
            ->assertHasFormErrors(['logo_path' => 'required']);

        Livewire::test(RegisterCompany::class)
            ->fillForm([
                'name' => 'Brown Coffee Test',
                'logo_path' => UploadedFile::fake()->image('logo.png', 200, 200),
                'cover_path' => UploadedFile::fake()->image('cover.jpg', 1200, 500),
                'tagline' => 'Coffee & brunch by the river',
                'branch_name' => 'Riverside',
                'currency' => 'USD',
            ])
            ->call('register')
            ->assertHasNoFormErrors();

        $company = Company::query()->where('name', 'Brown Coffee Test')->firstOrFail();
        $this->assertSame('trial', $company->status->value);
        $this->assertSame('Riverside', $company->branches()->value('name'));
        $this->assertTrue($newOwner->canAccessTenant($company));
        $this->assertSame('Coffee & brunch by the river', $company->tagline);
        $this->assertStringStartsWith('logos/', $company->logo_path);
        $this->assertStringStartsWith('covers/', $company->cover_path);
        Storage::disk('public')->assertExists([$company->logo_path, $company->cover_path]);
        $this->assertFalse(Company::query()->where('name', 'No Logo Café')->exists());
    }

    public function test_menu_item_suggestions_are_saved_in_order_and_bump_the_menu(): void
    {
        $latte = MenuItem::query()->where('name_en', 'Iced latte')->firstOrFail();
        $rice = MenuItem::query()->where('name_en', 'Fried rice')->firstOrFail();
        $noodles = MenuItem::query()->where('name_en', 'Khmer rice noodles')->firstOrFail();
        $version = $this->company->fresh()->menu_version;

        Livewire::test(ListMenuItems::class)
            ->callTableAction('edit', $latte, ['suggestions' => [$noodles->id, $rice->id]])
            ->assertHasNoTableActionErrors();

        $this->assertSame([$noodles->id, $rice->id], $latte->fresh()->suggestions->pluck('id')->all());
        $this->assertGreaterThan($version, $this->company->fresh()->menu_version);

        // At most 3.
        $others = MenuItem::query()->where('company_id', $this->company->id)->whereKeyNot($latte->id)->limit(4)->pluck('id')->all();
        Livewire::test(ListMenuItems::class)
            ->callTableAction('edit', $latte, ['suggestions' => $others])
            ->assertHasTableActionErrors(['suggestions']);

        // A new item can get suggestions straight away.
        Livewire::test(ListMenuItems::class)
            ->callAction('create', [
                'name_km' => 'នំខេក',
                'name_en' => 'Cake',
                'category_id' => $this->company->categories()->value('id'),
                'price' => '1.50',
                'station' => 'kitchen',
                'suggestions' => [$latte->id],
            ])
            ->assertHasNoActionErrors();

        $this->assertSame([$latte->id], MenuItem::query()->where('name_en', 'Cake')->firstOrFail()->suggestions->pluck('id')->all());
    }

    public function test_menu_item_suggestions_from_another_restaurant_cannot_be_chosen(): void
    {
        Filament::setTenant(null, isQuiet: true);
        $other = Company::query()->create(['name' => 'Other Café', 'slug' => 'other-cafe']);
        $category = Category::query()->create(['company_id' => $other->id, 'name_km' => 'ផឹក', 'name_en' => 'Drinks']);
        $foreign = MenuItem::query()->create([
            'company_id' => $other->id, 'category_id' => $category->id, 'name_km' => 'ទឹក', 'name_en' => 'Foreign water', 'price' => 100,
        ]);
        Filament::setTenant($this->company);

        $latte = MenuItem::query()->where('name_en', 'Iced latte')->firstOrFail();

        Livewire::test(ListMenuItems::class)
            ->callTableAction('edit', $latte, ['suggestions' => [$foreign->id]]);

        $this->assertFalse($latte->fresh()->suggestions->contains('id', $foreign->id));
    }
}
