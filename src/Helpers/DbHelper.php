<?php

namespace GuaranteedSoftware\Laravel\DatasourceTools\Helpers;

use Illuminate\Support\Facades\DB;

/**
 * DB Utility functions to be used across these data source tools
 */
class DbHelper
{
    /**
     * Identifies if the given table has already been partitioned
     *
     * @param string $tableName whose 'Create_options' are checked for partitions
     *
     * @return bool true if the table has been partitioned, otherwise false
     */
    public static function discoversTableIsAlreadyPartitioned(string $tableName): bool {
        $tableStatus = DB::select(DB::raw("SHOW TABLE STATUS LIKE '{$tableName}';"));

        return str_contains((string)$tableStatus[0]?->Create_options, 'partitioned');
    }
}
