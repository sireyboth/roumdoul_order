<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Wide photo shown behind the restaurant name at the top of the customer menu.
            $table->string('cover_path')->nullable()->after('logo_path');
            // One short line under the name, e.g. "Coffee & brunch in BKK1".
            $table->string('tagline', 120)->nullable()->after('cover_path');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['cover_path', 'tagline']);
        });
    }
};
