<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name_km');
            $table->string('name_en');
            $table->string('name_zh')->nullable();
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'is_active', 'sort_order']);
        });

        // The company's master menu. Branches only store what differs (branch_menu_items).
        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('name_km');
            $table->string('name_en');
            $table->string('name_zh')->nullable();
            $table->text('description_km')->nullable();
            $table->text('description_en')->nullable();
            $table->string('image_path')->nullable();
            // Minor units of the company currency (USD cents / whole riel).
            $table->unsignedInteger('price');
            $table->string('sku', 40)->nullable();
            // Which screen the ticket goes to: kitchen or bar.
            $table->string('station', 20)->default('kitchen');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'category_id', 'is_active']);
        });

        // Size, Sugar level, Ice, Toppings, Spice level...
        Schema::create('option_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name_km');
            $table->string('name_en');
            // min 1 + max 1 = pick exactly one (required). min 0 + max 3 = up to three extras.
            $table->unsignedTinyInteger('min_select')->default(0);
            $table->unsignedTinyInteger('max_select')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('option_group_id')->constrained()->cascadeOnDelete();
            $table->string('name_km');
            $table->string('name_en');
            // Added to the item price, in minor units. Can be 0. Signed so "no ice" could be a discount.
            $table->integer('price_delta')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('menu_item_option_group', function (Blueprint $table) {
            $table->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('option_group_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->primary(['menu_item_id', 'option_group_id']);
        });

        // Per-branch differences from the master menu. One row per branch x item.
        Schema::create('branch_menu_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            // false = this branch never sells it (e.g. Branch A has no chicken).
            $table->boolean('is_available')->default(true);
            // null = use the company price.
            $table->unsignedInteger('price')->nullable();
            // Set when staff tap "sold out". Null or in the past = can be ordered.
            $table->timestamp('sold_out_until')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'menu_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_menu_items');
        Schema::dropIfExists('menu_item_option_group');
        Schema::dropIfExists('options');
        Schema::dropIfExists('option_groups');
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('categories');
    }
};
