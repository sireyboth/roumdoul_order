<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B1 fixes (docs/database.md, "Fixes to existing tables" #1–#3):
 * sales history can never be erased by deleting a company or branch,
 * retry protection also works for orders without a table, and MySQL
 * guarantees one open visit per table.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['table_sessions', 'orders', 'order_items'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropForeign(['company_id']);
                $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();

                if ($name !== 'order_items') {
                    $table->dropForeign(['branch_id']);
                    $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
                }
            });
        }

        // Guards below let the migration re-run after a partial run (MySQL DDL is not transactional).
        if (! Schema::hasIndex('orders', ['branch_id', 'idempotency_key'], 'unique')) {
            Schema::table('orders', function (Blueprint $table) {
                // MySQL needs its own index for the dining_table_id foreign key before the old unique goes.
                if (! Schema::hasIndex('orders', ['dining_table_id'])) {
                    $table->index('dining_table_id');
                }
                $table->dropUnique(['dining_table_id', 'idempotency_key']);
                $table->unique(['branch_id', 'idempotency_key']);
            });
        }

        if ($this->isMySql() && ! Schema::hasColumn('table_sessions', 'open_table_id')) {
            Schema::table('table_sessions', function (Blueprint $table) {
                // Virtual, not stored: MySQL 8 forbids a stored generated column whose base
                // column (dining_table_id) has an ON DELETE CASCADE foreign key.
                $table->unsignedBigInteger('open_table_id')
                    ->nullable()
                    ->virtualAs("IF(status <> 'closed', dining_table_id, NULL)");
                $table->unique('open_table_id');
            });
        }
    }

    public function down(): void
    {
        if ($this->isMySql()) {
            Schema::table('table_sessions', function (Blueprint $table) {
                $table->dropUnique(['open_table_id']);
                $table->dropColumn('open_table_id');
            });
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'idempotency_key']);
            $table->unique(['dining_table_id', 'idempotency_key']);
            $table->dropIndex(['dining_table_id']);
        });

        foreach (['table_sessions', 'orders', 'order_items'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropForeign(['company_id']);
                $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();

                if ($name !== 'order_items') {
                    $table->dropForeign(['branch_id']);
                    $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
                }
            });
        }
    }

    private function isMySql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
