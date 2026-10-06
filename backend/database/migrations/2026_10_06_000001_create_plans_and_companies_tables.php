<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('description')->nullable();
            // null = unlimited
            $table->unsignedInteger('max_branches')->nullable();
            $table->unsignedInteger('max_tables')->nullable();
            $table->unsignedInteger('max_staff')->nullable();
            // Money is always stored as whole minor units (cents), never floats.
            $table->unsignedInteger('price_monthly_cents')->default(0);
            $table->unsignedInteger('price_yearly_cents')->default(0);
            $table->char('currency', 3)->default('USD');
            // Feature keys this plan unlocks, e.g. ["telegram", "khqr_auto", "kiosk"].
            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug', 80)->unique();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('logo_path')->nullable();
            $table->string('timezone', 64)->default('Asia/Phnom_Penh');
            // Menu prices are stored in this currency's minor unit (USD cents, or whole riel for KHR).
            $table->char('currency', 3)->default('USD');
            // Riel per 1 USD, shown next to USD totals and saved on each payment later.
            $table->unsignedInteger('khr_per_usd')->default(4100);
            // Basis points: 1000 = 10.00 %
            $table->unsignedSmallInteger('vat_bp')->default(0);
            $table->unsignedSmallInteger('service_charge_bp')->default(0);
            $table->boolean('prices_include_vat')->default(true);
            $table->string('status', 20)->default('trial'); // trial, active, suspended, cancelled
            $table->timestamp('trial_ends_at')->nullable();
            // Bumped on any company-wide menu change; part of every menu cache key.
            $table->unsignedInteger('menu_version')->default(1);
            $table->string('telegram_chat_id', 64)->nullable();
            // Link to the same business in coreos (HR/attendance), for single sign-on later.
            $table->unsignedBigInteger('coreos_company_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('active'); // trialing, active, past_due, cancelled
            $table->string('interval', 10)->default('monthly'); // monthly, yearly
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        // Who works for which company, and as what. One user can belong to several companies.
        Schema::create('company_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20); // owner, manager, cashier, kitchen, waiter
            // Hashed 4-6 digit PIN for manager approvals (voids, refunds) in Step 1.
            $table->string('pin_hash')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_user');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('companies');
        Schema::dropIfExists('plans');
    }
};
