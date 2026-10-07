<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drawn table layout for the staff screens. One plan per (branch, area);
        // area null = the "Main floor". Uniqueness is enforced in code (NULL breaks a unique index).
        Schema::create('floor_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('table_area_id')->nullable()->constrained()->nullOnDelete();
            $table->string('floor', 20)->default('wood'); // wood, tile, stone, carpet, grass, concrete
            $table->unsignedSmallInteger('width')->default(1200);
            $table->unsignedSmallInteger('height')->default(800);
            // [{id, kind, x, y, w, h, rotation, table_id, seats, label, color}, ...]
            $table->json('objects');
            $table->timestamps();

            $table->index(['branch_id', 'table_area_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('floor_plans');
    }
};
