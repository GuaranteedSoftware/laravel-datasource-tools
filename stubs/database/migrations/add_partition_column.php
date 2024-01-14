<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add created_date column to the wms_requests table.
 * It will be used for partitioning.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('{{tableName}}', function (Blueprint $table) {
            $table->date('{{partitionColumnName}}')->default(DB::raw('(CURRENT_DATE)'))->index();
            $table->unique(['id']);
            $table->dropPrimary(['id']);
            $table->primary(['id', '{{partitionColumnName}}']);
        });

        if (Schema::hasColumn('{{tableName}}', 'created_at')) {
            DB::statement(DB::raw("UPDATE {{tableName}} SET {{partitionColumnName}} = date(created_at);"));
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('{{tableName}}', function (Blueprint $table) {
            $table->dropPrimary(['id', '{{partitionColumnName}}']);
            $table->primary(['id']);
            $table->dropUnique(['id']);

            $table->dropIndex(['{{partitionColumnName}}']);
            $table->dropColumn(['{{partitionColumnName}}']);
        });
    }
};