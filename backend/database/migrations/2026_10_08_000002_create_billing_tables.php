<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Bills, discounts and payments (Step 1 part B, B1). All money in minor units. */
return new class extends Migration
{
    public function up(): void
    {
        // One bill per table visit.
        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('table_session_id')->unique()->constrained()->restrictOnDelete();
            // Receipt number: restarts at 1 every business day, per branch.
            $table->unsignedInteger('number');
            $table->date('business_date');
            $table->char('currency', 3);
            // Copied from the company when the bill opens, so old receipts never change.
            $table->unsignedInteger('khr_per_usd');
            $table->unsignedInteger('service_charge_bp')->default(0);
            $table->unsignedInteger('vat_bp')->default(0);
            $table->boolean('prices_include_vat')->default(true);
            $table->unsignedInteger('subtotal')->default(0);
            $table->unsignedInteger('discount_total')->default(0);
            $table->unsignedInteger('service_charge')->default(0);
            $table->unsignedInteger('vat')->default(0);
            $table->unsignedInteger('total')->default(0);
            $table->unsignedBigInteger('total_khr')->default(0); // rounded to the nearest 100៛
            $table->unsignedInteger('paid_total')->default(0); // sum of confirmed payments
            $table->string('status', 20)->default('open'); // open, paid, void
            $table->foreignId('opened_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['branch_id', 'business_date', 'number']);
            $table->index(['branch_id', 'status']);
            $table->index(['company_id', 'business_date']);
        });

        // Discounts. Always approved with a manager PIN.
        Schema::create('bill_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('bill_id')->constrained()->restrictOnDelete();
            $table->string('type', 10); // percent, fixed
            // percent: basis points (1000 = 10%); fixed: minor units in the bill currency
            $table->unsignedInteger('value');
            $table->unsignedInteger('amount')->default(0); // what it took off, set by BillCalculator
            $table->string('reason', 255);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Removed discounts stay for the audit trail.
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['bill_id', 'removed_at']);
        });

        // A bill can have several payments (part cash, part KHQR).
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('bill_id')->constrained()->restrictOnDelete();
            // Cash drawer shift; the shifts table and its foreign key arrive in B3.
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->string('idempotency_key', 64);
            $table->string('method', 20); // cash, khqr, card, other
            $table->unsignedInteger('amount'); // applied to the bill, in the bill currency
            $table->unsignedBigInteger('tendered_amount')->nullable(); // e.g. 50000 when 50,000៛ is handed over
            $table->char('tendered_currency', 3)->nullable();
            $table->unsignedBigInteger('change_amount')->default(0);
            $table->char('change_currency', 3)->nullable();
            $table->unsignedInteger('khr_per_usd');
            $table->string('reference', 100)->nullable(); // KHQR transaction id
            $table->string('status', 20)->default('confirmed'); // confirmed, refunded
            $table->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at');
            $table->date('business_date');
            $table->string('refund_reason', 255)->nullable();
            $table->foreignId('refunded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'idempotency_key']);
            $table->unique(['branch_id', 'reference']);
            $table->index('bill_id');
            $table->index(['shift_id', 'method']);
            $table->index(['branch_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('bill_adjustments');
        Schema::dropIfExists('bills');
    }
};
