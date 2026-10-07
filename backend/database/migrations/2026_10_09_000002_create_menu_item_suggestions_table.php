<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Goes well with": after adding menu_item_id, the customer is offered suggested_item_id.
        Schema::create('menu_item_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('suggested_item_id')->constrained('menu_items')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['menu_item_id', 'suggested_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_item_suggestions');
    }
};
