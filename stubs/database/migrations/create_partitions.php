<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migration to initially partition a table with partitions tagged by date.
 * The data already stored in the database is split into "historic" partitions.
 * All data older than the first tag date is put in the first, and "oldest" historic partition.
 * All other data will be placed in their matching historic partitions.
 * Additionally, inactive partitions may be created for future dates if the
 * {@see \Console\Commands\PartitionTableByDate::$endDate} is
 * in the future.  These partitions become active historic partitions when their tag date arrives.
 * Finally, a fallback `pMAXVALUE` partition is created. In the event that the current date exceeds
 * any created partition, the data will be stored in the otherwise perennially inactive `pMAXVALUE` partition.
 *
 * @see App\Console\Commands\PartitionTable
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
        DB::statement
        (
            "
                {{statement}}
            "
        );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::select(DB::raw("ALTER TABLE {{tableName}} REMOVE PARTITIONING;"));
    }
};
