<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stops orders from people who are not in the shop (a photo of the QR taken home):
 * the phone sends its location and the server checks it is near the branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->boolean('require_location')->default(false)->after('longitude');
            // Metres around the branch's point where orders are accepted.
            $table->unsignedSmallInteger('order_radius_m')->default(150)->after('require_location');
        });

        Schema::table('orders', function (Blueprint $table) {
            // How far the phone was from the branch when ordering (null = not checked).
            $table->unsignedInteger('customer_distance_m')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('customer_distance_m'));
        Schema::table('branches', fn (Blueprint $table) => $table->dropColumn(['require_location', 'order_radius_m']));
    }
};
