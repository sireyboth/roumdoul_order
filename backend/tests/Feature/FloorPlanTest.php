<?php

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\DiningTable;
use App\Models\FloorPlan;
use App\Models\User;
use App\Services\CompanyProvisioner;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The drawn table layout on the staff screens: everyone sees it, owners/managers edit it. */
class FloorPlanTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->company = Company::query()->where('slug', 'demo-cafe')->firstOrFail();
        $this->branch = $this->company->branches()->firstOrFail();
    }

    private function token(string $email): string
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/staff/login', ['email' => $email, 'password' => 'password'])->json('data.token');
    }

    private function url(?Branch $branch = null): string
    {
        return '/api/staff/branches/'.($branch ?? $this->branch)->id.'/floor-plan';
    }

    private function table(string $name): DiningTable
    {
        return $this->branch->diningTables()->where('name', $name)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function object(string $id, string $kind, array $extra = []): array
    {
        return ['id' => $id, 'kind' => $kind, 'x' => 100, 'y' => 100, 'w' => 80, 'h' => 80, 'rotation' => 0, ...$extra];
    }

    /** @return array<string, mixed> */
    private function body(?int $areaId, array $objects): array
    {
        return ['area_id' => $areaId, 'floor' => 'tile', 'width' => 1000, 'height' => 600, 'objects' => $objects];
    }

    public function test_waiter_sees_the_seeded_plan_but_cannot_edit(): void
    {
        $token = $this->token('waiter@roumdoul.test');

        $response = $this->withToken($token)->getJson($this->url())
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.can_edit', false)
            ->assertJsonPath('data.areas.0.name', 'Indoor')
            ->assertJsonPath('data.areas.1.name', 'Terrace')
            ->assertJsonCount(6, 'data.tables')
            ->assertJsonPath('data.tables.0.name', 'T1')
            ->assertJsonPath('data.tables.3.seats', 6)
            ->assertJsonCount(1, 'data.floors')
            ->assertJsonPath('data.floors.0.area_id', null)
            ->assertJsonPath('data.floors.0.floor', 'wood')
            ->assertJsonPath('data.floors.0.width', 1200)
            ->assertJsonCount(17, 'data.floors.0.objects');

        $t3 = collect($response->json('data.floors.0.objects'))->firstWhere('id', 'seed-t3');
        $this->assertSame('table_round', $t3['kind']);
        $this->assertSame($this->table('T3')->id, $t3['table_id']);
        $this->assertIsString($response->json('data.floors.0.updated_at'));

        $this->withToken($token)->putJson($this->url(), $this->body(null, []))->assertForbidden();
    }

    public function test_owner_saves_a_floor_updates_seats_and_moves_tables_between_floors(): void
    {
        $token = $this->token('owner@roumdoul.test');
        $terrace = $this->branch->tableAreas()->where('name', 'Terrace')->firstOrFail();
        $t5 = $this->table('T5');
        $t6 = $this->table('T6');

        $this->withToken($token)->getJson($this->url())->assertJsonPath('data.can_edit', true);

        $this->withToken($token)->putJson($this->url(), $this->body($terrace->id, [
            $this->object('t5', 'table_round', ['table_id' => $t5->id, 'seats' => 8, 'unknown' => 'dropped']),
            $this->object('t6', 'booth', ['table_id' => $t6->id]),
            $this->object('p1', 'plant', ['table_id' => $t5->id, 'seats' => 3, 'label' => 'Palm', 'color' => '#00aa33']),
        ]))
            ->assertOk()
            ->assertJsonPath('data.can_edit', true)
            ->assertJsonCount(2, 'data.floors')
            ->assertJsonPath('data.floors.1.area_id', $terrace->id)
            ->assertJsonPath('data.floors.1.floor', 'tile')
            ->assertJsonPath('data.floors.1.objects.0', [
                'id' => 't5', 'kind' => 'table_round', 'x' => 100, 'y' => 100, 'w' => 80, 'h' => 80, 'rotation' => 0,
                'table_id' => $t5->id, 'seats' => 8, 'label' => null, 'color' => null,
            ])
            // A plant is not a table: table_id and seats are ignored.
            ->assertJsonPath('data.floors.1.objects.2.table_id', null)
            ->assertJsonPath('data.floors.1.objects.2.seats', null)
            ->assertJsonPath('data.floors.1.objects.2.label', 'Palm');

        $this->assertSame(8, (int) $t5->fresh()->seats);
        $this->assertSame(2, (int) $t6->fresh()->seats, 'No seats sent = unchanged');

        // T5 and T6 left the main floor; the other tables stayed.
        $main = FloorPlan::query()->where('branch_id', $this->branch->id)->whereNull('table_area_id')->firstOrFail();
        $tableIds = collect($main->objects)->pluck('table_id')->filter()->values()->all();
        $this->assertNotContains($t5->id, $tableIds);
        $this->assertNotContains($t6->id, $tableIds);
        $this->assertCount(4, $tableIds);
        $this->assertCount(15, $main->objects);

        // Saving the same floor again updates it in place.
        $this->withToken($token)->putJson($this->url(), $this->body($terrace->id, []))->assertOk()->assertJsonCount(2, 'data.floors');
        $this->assertSame(2, FloorPlan::query()->where('branch_id', $this->branch->id)->count());
        $this->assertTrue(AuditLog::query()->where('event', 'floor_plan.saved')->exists());
    }

    public function test_manager_can_edit_the_main_floor(): void
    {
        $manager = User::factory()->create(['email' => 'manager@roumdoul.test', 'password' => 'password', 'is_active' => true]);
        $this->company->memberships()->create(['user_id' => $manager->id, 'role' => StaffRole::Manager, 'is_active' => true]);
        $t1 = $this->table('T1');

        $this->withToken($this->token('manager@roumdoul.test'))
            ->putJson($this->url(), $this->body(null, [$this->object('a', 'table_long', ['table_id' => $t1->id, 'seats' => 0])]))
            ->assertOk()
            ->assertJsonCount(1, 'data.floors')
            ->assertJsonCount(1, 'data.floors.0.objects');

        $this->assertNull($t1->fresh()->seats, 'Zero seats = not set');
    }

    public function test_invalid_plans_are_rejected(): void
    {
        $token = $this->token('owner@roumdoul.test');
        $t1 = $this->table('T1');

        // A table of another restaurant.
        $other = app(CompanyProvisioner::class)->create(['name' => 'Other Place'], User::factory()->create());
        $foreignTable = DiningTable::query()->create(['branch_id' => $other->branches()->value('id'), 'name' => 'X1']);
        $this->withToken($token)->putJson($this->url(), $this->body(null, [$this->object('x', 'table_square', ['table_id' => $foreignTable->id])]))
            ->assertUnprocessable()->assertJsonValidationErrors('objects.0.table_id');

        // A table of another branch of the same restaurant.
        $tk = Branch::query()->create(['company_id' => $this->company->id, 'name' => 'Toul Kork', 'code' => 'TK']);
        $tkTable = DiningTable::query()->create(['branch_id' => $tk->id, 'name' => 'K1']);
        $this->withToken($token)->putJson($this->url(), $this->body(null, [$this->object('x', 'table_square', ['table_id' => $tkTable->id])]))
            ->assertUnprocessable()->assertJsonValidationErrors('objects.0.table_id');

        // An inactive table, a table twice, a table without table_id.
        $t2 = $this->table('T2');
        $t2->update(['is_active' => false]);
        $this->withToken($token)->putJson($this->url(), $this->body(null, [
            $this->object('a', 'table_square', ['table_id' => $t2->id]),
            $this->object('b', 'table_square', ['table_id' => $t1->id]),
            $this->object('c', 'table_round', ['table_id' => $t1->id]),
        ]))->assertUnprocessable()->assertJsonValidationErrors(['objects.0.table_id', 'objects.2.table_id'])
            ->assertJsonMissingValidationErrors('objects.1.table_id');
        $this->withToken($token)->putJson($this->url(), $this->body(null, [$this->object('d', 'booth')]))
            ->assertUnprocessable()->assertJsonValidationErrors('objects.0.table_id');

        // Unknown kind, bad id, bad colour, out-of-range numbers, area of another branch, bad floor.
        $tkArea = $tk->tableAreas()->create(['company_id' => $this->company->id, 'name' => 'Upstairs']);
        $this->withToken($token)->putJson($this->url(), [
            'area_id' => $tkArea->id,
            'floor' => 'lava',
            'width' => 100,
            'height' => 9000,
            'objects' => [
                $this->object('ok', 'swimming_pool'),
                $this->object('bad id!', 'plant', ['color' => 'red', 'rotation' => 360, 'w' => 5]),
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'area_id', 'floor', 'width', 'height', 'objects.0.kind', 'objects.1.id', 'objects.1.color', 'objects.1.rotation', 'objects.1.w',
        ]);

        // Nothing was changed.
        $this->assertSame(1, FloorPlan::query()->where('branch_id', $this->branch->id)->count());
        $this->assertCount(17, FloorPlan::query()->where('branch_id', $this->branch->id)->firstOrFail()->objects);
    }

    public function test_staff_of_another_restaurant_get_403(): void
    {
        $outsider = User::factory()->create(['email' => 'outsider@roumdoul.test', 'password' => 'password', 'is_active' => true]);
        app(CompanyProvisioner::class)->create(['name' => 'Other Place'], $outsider);
        $token = $this->token('outsider@roumdoul.test');

        $this->withToken($token)->getJson($this->url())->assertForbidden();
        $this->withToken($token)->putJson($this->url(), $this->body(null, []))->assertForbidden();
    }
}
