<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Proof that an owner agreed to the terms (frontend /terms), at sign-up and at every plan change.
        // Never updated or deleted.
        Schema::create('terms_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // The terms date shown on /terms, e.g. 2026-10-13 (config tok.terms_version).
            $table->string('version', 20);
            // signup, plan_change
            $table->string('context', 20);
            // e.g. "Starter → Pro (monthly), $10.32 now"
            $table->string('detail')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terms_acceptances');
    }
};
