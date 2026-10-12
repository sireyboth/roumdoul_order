<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\MenuBuilder;
use App\Support\Uploads;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Pictures follow UPLOADS_DISK, so production can use a bucket (Laravel Cloud wipes the local disk on deploy). */
class UploadsDiskTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_to_the_public_disk(): void
    {
        $this->assertSame('public', Uploads::disk());
        $this->assertNull(Uploads::url(null));
    }

    public function test_logo_and_menu_links_use_the_configured_disk(): void
    {
        $this->seed(DatabaseSeeder::class);
        config(['filesystems.uploads' => 'bucket']);
        Storage::fake('bucket');

        $company = Company::query()->where('slug', 'demo-cafe')->firstOrFail();
        $company->update(['logo_path' => 'logos/demo.png']);

        $expected = Storage::disk('bucket')->url('logos/demo.png');
        $this->assertSame($expected, $company->fresh()->getFilamentAvatarUrl());

        $table = $company->diningTables()->firstOrFail();
        $menu = app(MenuBuilder::class)->forToken($table->qr_token);
        $this->assertSame($expected, data_get($menu, 'company.logo_url'));
    }
}
