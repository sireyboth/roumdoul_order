<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 20)->nullable();
            $table->string('address')->nullable();
            $table->string('phone', 30)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            // Sales after midnight but before this time count toward the previous business day.
            $table->time('day_ends_at')->default('04:00:00');
            // {"mon": [["07:00","22:00"]], ...}
            $table->json('opening_hours')->nullable();
            $table->boolean('is_active')->default(true);
            // Bumped when this branch's availability, prices or sold-out items change.
            $table->unsignedInteger('menu_version')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedBigInteger('coreos_branch_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
        });

        // Limits a staff member to some branches. No rows = all branches.
        Schema::create('branch_user', function (Blueprint $table) {
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['branch_id', 'user_id']);
        });

        Schema::create('table_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // Indoor, Terrace, VIP room
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('dining_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('table_area_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 40); // T1, A-05, Bar 3
            $table->unsignedTinyInteger('seats')->nullable();
            // The only thing a customer's phone knows. Random, never a sequential id.
            $table->string('qr_token', 40)->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['branch_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dining_tables');
        Schema::dropIfExists('table_areas');
        Schema::dropIfExists('branch_user');
        Schema::dropIfExists('branches');
    }
};
