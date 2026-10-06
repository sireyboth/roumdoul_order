<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Report copies (Step 1 part B, B5). Never edited by hand: App\Services\Reports\DailySales
 * recomputes a whole branch-day from bills, payments and orders, so the copy can always be
 * rebuilt (php artisan reports:rebuild). Money in minor units of the company currency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_branch_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');
            $table->char('currency', 3);
            $table->unsignedInteger('orders_count')->default(0); // not cancelled
            $table->unsignedInteger('cancelled_count')->default(0);
            $table->unsignedInteger('bills_count')->default(0); // paid
            $table->unsignedInteger('items_count')->default(0);
            $table->unsignedBigInteger('gross')->default(0); // subtotal of paid bills
            $table->unsignedBigInteger('discounts')->default(0);
            $table->unsignedBigInteger('service_charge')->default(0);
            $table->unsignedBigInteger('vat')->default(0);
            $table->unsignedBigInteger('net')->default(0); // total of paid bills
            $table->unsignedBigInteger('refunds')->default(0);
            $table->unsignedBigInteger('cash')->default(0);
            $table->unsignedBigInteger('khqr')->default(0);
            $table->unsignedBigInteger('card')->default(0);
            $table->unsignedBigInteger('other')->default(0);
            $table->timestamp('summary_sent_at')->nullable(); // Telegram day-end summary
            $table->timestamps();

            $table->unique(['branch_id', 'business_date']);
            $table->index(['company_id', 'business_date']);
        });

        Schema::create('daily_item_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');
            $table->foreignId('menu_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name_en');
            $table->string('name_km');
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedBigInteger('amount')->default(0);
            $table->timestamps();

            $table->unique(['branch_id', 'business_date', 'menu_item_id']);
            $table->index(['company_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_item_sales');
        Schema::dropIfExists('daily_branch_sales');
    }
};
