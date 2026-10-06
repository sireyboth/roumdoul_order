<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One visit to a table: opened by the first order, closed when paid.
        Schema::create('table_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dining_table_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('open'); // open, bill_requested, closed
            $table->timestamp('opened_at');
            $table->timestamp('bill_requested_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['dining_table_id', 'status']);
            $table->index(['branch_id', 'status']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dining_table_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('table_session_id')->nullable()->constrained()->nullOnDelete();
            // Short number staff call out: restarts at 1 every business day, per branch.
            $table->unsignedInteger('number');
            $table->date('business_date');
            $table->string('status', 20)->default('placed');
            $table->string('source', 20)->default('qr'); // qr, waiter
            $table->foreignId('placed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Sent by the phone with every "Send order" tap; a retry with the same key returns the same order.
            $table->string('idempotency_key', 64)->nullable();
            $table->string('note', 255)->nullable();
            $table->char('currency', 3);
            // Minor units (cents / riel). Computed on the server only.
            $table->unsignedInteger('subtotal');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('preparing_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('served_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['branch_id', 'business_date', 'number']);
            $table->unique(['dining_table_id', 'idempotency_key']);
            $table->index(['branch_id', 'status', 'created_at']);
            $table->index(['company_id', 'business_date']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_item_id')->nullable()->constrained()->nullOnDelete();
            // Copied at order time so editing the menu later never changes past orders.
            $table->string('name_km');
            $table->string('name_en');
            $table->string('station', 20);
            $table->unsignedInteger('unit_price'); // item price + chosen options
            $table->unsignedSmallInteger('quantity');
            $table->unsignedInteger('line_total');
            $table->json('options')->nullable(); // [{id, group_en, name_km, name_en, price_delta}]
            $table->string('note', 120)->nullable();
            $table->timestamps();
        });

        // "Call waiter", "Request bill"
        Schema::create('service_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dining_table_id')->constrained()->cascadeOnDelete();
            $table->foreignId('table_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20); // waiter, bill
            $table->string('status', 20)->default('open'); // open, done
            $table->foreignId('handled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_requests');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('table_sessions');
    }
};
