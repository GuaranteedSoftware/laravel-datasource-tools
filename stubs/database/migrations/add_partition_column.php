<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add the column to the table that will be used for partitioning.
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
        /* @see \GuaranteedSoftware\Laravel\\DatasourceTools\Console\Commands\PartitionTableByDateRange::addPartitionColumnToBlueprint() */
        Schema::table('{{tableName}}', function (Blueprint $table) {
            $table->date('{{partitionColumnName}}')->default(DB::raw('(CURRENT_DATE)'))->index();
            $table->unique(['id']);
            $table->dropPrimary(['id']);
            $table->primary(['id', '{{partitionColumnName}}']);
        });

        if (Schema::hasColumn('{{tableName}}', 'created_at')) {
            DB::statement("UPDATE {{tableName}} SET {{partitionColumnName}} = date(created_at);");
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
