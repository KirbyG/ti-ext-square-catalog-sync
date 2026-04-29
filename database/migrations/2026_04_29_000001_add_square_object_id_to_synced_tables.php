<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds square_object_id (indexed) to every table we sync from Square.
 *
 * This is the foreign key into Square that makes incremental sync,
 * deletion handling, and webhook routing tractable.
 *
 * Table names are TastyIgniter defaults — verify against your TI version
 * if any Schema::hasTable() check fails.
 */
return new class extends Migration
{
    /** Tables that receive square_object_id + soft-delete columns */
    private array $tables = [
        'taxes',
        'categories',
        'menus',
        'menu_options',
        'menu_option_values',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                continue; // Table may not exist yet in a fresh install
            }

            Schema::table($table, function (Blueprint $table) {
                if (! Schema::hasColumn($table->getTable(), 'square_object_id')) {
                    $table->string('square_object_id', 128)->nullable()->unique()->index();
                }

                // Soft-delete support (some TI tables already have deleted_at)
                if (! Schema::hasColumn($table->getTable(), 'deleted_at')) {
                    $table->softDeletes();
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $table) {
                if (Schema::hasColumn($table->getTable(), 'square_object_id')) {
                    $table->dropColumn('square_object_id');
                }
                if (Schema::hasColumn($table->getTable(), 'deleted_at')) {
                    $table->dropSoftDeletes();
                }
            });
        }
    }
};
