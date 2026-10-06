<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Printing (Step 1 part B, B4): receipt text per branch, kitchen auto-print, the shop's KHQR picture. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('receipt_header', 500)->nullable()->after('opening_hours');
            $table->string('receipt_footer', 500)->nullable()->after('receipt_header');
            $table->boolean('auto_print_kitchen')->default(false)->after('receipt_footer');
        });

        Schema::table('companies', function (Blueprint $table) {
            // The static KHQR the bank gave the shop, printed on bills so customers can scan and pay.
            $table->string('khqr_image_path')->nullable()->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['receipt_header', 'receipt_footer', 'auto_print_kitchen']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('khqr_image_path');
        });
    }
};
