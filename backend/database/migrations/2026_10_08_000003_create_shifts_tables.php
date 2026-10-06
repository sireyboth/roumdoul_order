<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cash drawer shifts (Step 1 part B, B3). Cash is counted separately in
 * dollars (cents) and riel, because a Cambodian drawer holds both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('open'); // open, closed
            $table->foreignId('opened_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at');
            $table->unsignedBigInteger('opening_cash_usd')->default(0); // cents
            $table->unsignedBigInteger('opening_cash_khr')->default(0); // riel
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            // Worked out when the shift closes; signed because a drawer can be short.
            $table->bigInteger('expected_cash_usd')->nullable();
            $table->bigInteger('expected_cash_khr')->nullable();
            $table->unsignedBigInteger('counted_cash_usd')->nullable();
            $table->unsignedBigInteger('counted_cash_khr')->nullable();
            $table->bigInteger('difference_usd')->nullable(); // counted − expected
            $table->bigInteger('difference_khr')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index(['company_id', 'opened_at']);
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            // One open shift per branch, guaranteed by the database (the app also locks the branch).
            Schema::table('shifts', function (Blueprint $table) {
                $table->unsignedBigInteger('open_branch_id')->nullable()->storedAs("IF(status = 'open', branch_id, NULL)");
                $table->unique('open_branch_id');
            });
        }

        // Cash put into or taken out of the drawer that is not a sale: change float, paying a supplier.
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->string('type', 10); // in, out
            $table->unsignedBigInteger('amount'); // cents or riel, see currency
            $table->char('currency', 3); // USD, KHR
            $table->string('reason', 255);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index('shift_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('shift_id')->references('id')->on('shifts')->restrictOnDelete();
            // A cash refund leaves the drawer of the shift that is open when it happens.
            $table->foreignId('refunded_in_shift_id')->nullable()->after('refunded_at')->constrained('shifts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['shift_id']);
            $table->dropConstrainedForeignId('refunded_in_shift_id');
        });

        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('shifts');
    }
};
