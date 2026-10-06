<?php

namespace Tests\Feature;

use App\Events\BranchChanged;
use App\Events\TableChanged;
use App\Models\Company;
use App\Models\DiningTable;
use App\Models\MenuItem;
use App\Models\User;
use App\Services\CompanyProvisioner;
use App\Services\Ordering\OrderPlacer;
use App\Support\Live;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

class LiveUpdatesTest extends TestCase
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

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1',
        ]);

        // Channel rules attach to the broadcaster active at boot (null in tests): attach them to Reverb too.
        require base_path('routes/channels.php');
    }

    private function token(string $email): string
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/staff/login', ['email' => $email, 'password' => 'password'])->json('data.token');
    }

    public function test_a_new_order_tells_the_branch_and_the_table_once(): void
    {
        Bus::fake([BroadcastEvent::class]);
        $rice = MenuItem::query()->where('name_en', 'Khmer rice noodles')->firstOrFail();

        // Order, visit and audit rows are all saved, but each channel hears about it once.
        app(OrderPlacer::class)->place($this->table, [['menu_item_id' => $rice->id, 'quantity' => 1]], idempotencyKey: (string) Str::uuid());
        Live::flush();

        Bus::assertDispatchedTimes(BroadcastEvent::class, 2);
        Bus::assertDispatched(BroadcastEvent::class, fn ($job) => $job->event instanceof BranchChanged && $job->event->branchId === $this->table->branch_id);
        Bus::assertDispatched(BroadcastEvent::class, fn ($job) => $job->event instanceof TableChanged && $job->event->tableId === $this->table->id);
    }

    public function test_nothing_is_sent_when_live_updates_are_off(): void
    {
        config(['broadcasting.default' => 'null']);
        Bus::fake([BroadcastEvent::class]);
        $rice = MenuItem::query()->where('name_en', 'Khmer rice noodles')->firstOrFail();

        app(OrderPlacer::class)->place($this->table, [['menu_item_id' => $rice->id, 'quantity' => 1]], idempotencyKey: (string) Str::uuid());
        Live::flush();

        Bus::assertNotDispatched(BroadcastEvent::class);
        $this->getJson("/api/public/tables/{$this->table->qr_token}/session")->assertJsonPath('data.live_channel', null);
    }

    public function test_only_staff_of_the_branch_may_listen(): void
    {
        $channel = 'private-branch.'.$this->table->branch_id;

        $this->withToken($this->token('kitchen@roumdoul.test'))
            ->postJson('/api/staff/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel])
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $stranger = User::factory()->create(['password' => 'password']);
        app(CompanyProvisioner::class)->create(['name' => 'Rival'], $stranger);

        $this->withToken($this->token($stranger->email))
            ->postJson('/api/staff/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel])
            ->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->withoutToken()->postJson('/api/staff/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel])
            ->assertUnauthorized();
    }

    public function test_the_phone_gets_its_secret_table_channel(): void
    {
        $channel = $this->getJson("/api/public/tables/{$this->table->qr_token}/session")->json('data.live_channel');

        $this->assertSame(Live::tableChannel($this->table->id), $channel);
        $this->assertMatchesRegularExpression('/^table\.[a-f0-9]{32}$/', $channel);
        $this->assertStringNotContainsString((string) $this->table->id, substr($channel, 0, 6));
    }
}
