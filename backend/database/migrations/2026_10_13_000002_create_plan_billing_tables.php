<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Billing restaurants for their plan (docs/roadmap.md C2): price snapshot and scheduled
 * downgrade on the subscription, plan invoices (never deleted), platform payment settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Owner's Telegram (@username or phone), so the Roumdoul team can contact them.
            $table->string('contact_telegram', 64)->nullable()->after('phone');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            // Price agreed when this subscription started (cents per interval); later price list changes don't touch it.
            $table->unsignedInteger('price_cents')->default(0)->after('interval');
            $table->char('currency', 3)->default('USD')->after('price_cents');
            // Downgrade waiting for the next renewal (ends_at).
            $table->foreignId('scheduled_plan_id')->nullable()->after('currency')->constrained('plans')->nullOnDelete();
            $table->string('scheduled_interval', 10)->nullable()->after('scheduled_plan_id');
            // Set when a plan change replaced this subscription (history is kept, never deleted).
            $table->timestamp('ended_at')->nullable()->after('ends_at');
        });

        // Existing subscriptions keep today's price of their plan.
        foreach (DB::table('subscriptions')->get() as $row) {
            $plan = DB::table('plans')->find($row->plan_id);
            DB::table('subscriptions')->where('id', $row->id)->update([
                'price_cents' => $plan ? ($row->interval === 'yearly' ? $plan->price_yearly_cents : $plan->price_monthly_cents) : 0,
                'currency' => $plan->currency ?? 'USD',
            ]);
        }

        Schema::create('plan_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            // first (after the free trial), renewal, upgrade
            $table->string('kind', 16);
            // The plan and interval this invoice pays for.
            $table->foreignId('plan_id')->constrained('plans');
            $table->string('interval', 10);
            $table->unsignedInteger('amount_cents');
            $table->char('currency', 3)->default('USD');
            // How the amount was worked out, e.g. "($39.00 - $19.00) x 15/31 days".
            $table->string('description');
            $table->dateTime('period_starts_at');
            $table->dateTime('period_ends_at');
            $table->dateTime('due_at');
            // pending, paid, void
            $table->string('status', 10)->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            // The owner's transfer screenshot.
            $table->string('proof_path')->nullable();
            $table->string('proof_note', 255)->nullable();
            $table->timestamp('proof_sent_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            // aba, khqr, cash, other
            $table->string('paid_method', 10)->nullable();
            $table->string('paid_reference', 120)->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('overdue_notified_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['status', 'due_at']);
        });

        // Roumdoul's own settings (ABA QR for plan payments, contacts), edited in /admin > Payment settings.
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('plan_invoices');

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('scheduled_plan_id');
            $table->dropColumn(['price_cents', 'currency', 'scheduled_interval', 'ended_at']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('contact_telegram');
        });
    }
};
