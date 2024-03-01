<?php

namespace GuaranteedSoftware\Laravel\DatasourceTools\Console\Commands;

use Carbon\Carbon;
use GuaranteedSoftware\Laravel\DatasourceTools\Contracts\Constants;
use GuaranteedSoftware\Laravel\DatasourceTools\Helpers\DbHelper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * A command to delete an old partitions after a life span given in number of days, (historic). and to create
 * new ones a given number of days into the future
 *
 * Example usage (as run on 2023-09-25):
 *
 * php artisan table:update-partitions wms_requests
 *
 * EXECUTING STATEMENT:
 * ALTER TABLE wms_requests
 *             REORGANIZE PARTITION pMAXVALUE INTO
 *                 (PARTITION p20231002 VALUES LESS THAN
 *                 (to_days('2023-10-03')),
 *                 PARTITION pMAXVALUE VALUES LESS THAN MAXVALUE);
 * EXECUTING STATEMENT:
 * ALTER TABLE wms_requests DROP PARTITION p20230918;
 * RESULTED TABLE STRUCTURE:
 * CREATE TABLE `wms_requests` (
 *   `id` bigint unsigned NOT NULL AUTO_INCREMENT,
 *   `method` varchar(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
 *   `host` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
 *   `path` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
 *   `body` json DEFAULT NULL,
 *   `response_code` int NOT NULL,
 *   `response_body` json DEFAULT NULL,
 *   `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *   `created_date` date NOT NULL DEFAULT (curdate()),
 *   PRIMARY KEY (`id`,`created_date`),
 *   KEY `wms_requests_created_date_index` (`created_date`)
 * ) ENGINE=InnoDB AUTO_INCREMENT=93327 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
 * /*!50100 PARTITION BY RANGE (to_days(`created_date`))
 * (PARTITION p20230919 VALUES LESS THAN (739148) ENGINE = InnoDB,
 *  PARTITION p20230920 VALUES LESS THAN (739149) ENGINE = InnoDB,
 *  PARTITION p20230921 VALUES LESS THAN (739150) ENGINE = InnoDB,
 *  PARTITION p20230922 VALUES LESS THAN (739151) ENGINE = InnoDB,
 *  PARTITION p20230923 VALUES LESS THAN (739152) ENGINE = InnoDB,
 *  PARTITION p20230924 VALUES LESS THAN (739153) ENGINE = InnoDB,
 *  PARTITION p20230925 VALUES LESS THAN (739154) ENGINE = InnoDB,
 *  PARTITION p20230926 VALUES LESS THAN (739155) ENGINE = InnoDB,
 *  PARTITION p20230927 VALUES LESS THAN (739156) ENGINE = InnoDB,
 *  PARTITION p20230928 VALUES LESS THAN (739157) ENGINE = InnoDB,
 *  PARTITION p20230929 VALUES LESS THAN (739158) ENGINE = InnoDB,
 *  PARTITION p20230930 VALUES LESS THAN (739159) ENGINE = InnoDB,
 *  PARTITION p20231001 VALUES LESS THAN (739160) ENGINE = InnoDB,
 *  PARTITION p20231002 VALUES LESS THAN (739161) ENGINE = InnoDB,
 *  PARTITION pMAXVALUE VALUES LESS THAN MAXVALUE ENGINE = InnoDB)
 */
class UpdateTablePartitions extends Command
{
    /**
     * When partitioning the table it only makes sense to have at least 2 active partitions
     * (and the MAXVALUE fallback)
     *
     * TODO: Justify this statement - @danac
     *
     * @var int
     */
    public const MINIMUM_NUMBER_OF_PARTITIONS = 2;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'table:update-partitions
                            {tableName : The name of the partitioned table to add/drop partitions}
                            {futurePartitionCount=2 : Positive integer greater than 1. Number of pre-created partitions, one for each subsequent day in the future} 
                            {historicPartitionCount=7 : Positive integer greater than 1. Partition life span in days - Partitions are deleted beyond the `historicPartitionCount` past days}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command to delete old partitions and create a new ones';

    /**
     * The name of the table to partition
     *
     * @var string
     */
    private string $tableName= '';

    /**
     * Number of pre-created partitions, one for each subsequent day in the future.
     * Positive integer greater than 1.
     * Defaults to 2
     *
     * @var int
     */
    private int $futurePartitionCount = 2;

    /**
     * A partition's life span in days - Partitions are deleted beyond the `historicPartitionCount` number of
     * days in the past
     * Positive integer greater than 1.
     * Defaults to 7
     *
     * @var int
     */
    private int $historicPartitionCount = 7;

    /**
     * Execute the console command
     * to delete the old partitions beyond the "historic day count" and create a new ones the
     * "future partition count" into the future
     *
     * @return int  exit codes: SUCCESS = 0, FAILURE = 1, INVALID = 2
     */
    public function handle(): int
    {
        $this->tableName = $this->argument('tableName');
        $this->futurePartitionCount = $this->argument('futurePartitionCount');
        $this->historicPartitionCount = $this->argument('historicPartitionCount');

        try {
            $this->validateArguments();
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->call('help', ['command_name' => 'table:update-partitions', 'format' => 'raw']);
            return self::INVALID;
        }

        $futureBoundaryDate = Carbon::today()->add('days', $this->futurePartitionCount);
        $pastBoundaryDate = Carbon::today()->sub('days', $this->historicPartitionCount);

        $statements = [];

        $statements[] =
            "ALTER TABLE $this->tableName REORGANIZE PARTITION pMAXVALUE INTO
                (PARTITION p{$futureBoundaryDate->format(Constants::PARTITION_NAME_DATE_FORMAT)} VALUES LESS THAN 
                (to_days('{$futureBoundaryDate->add('days', 1)->format(Constants::MYSQL_DATE_FORMAT)}')), 
                PARTITION pMAXVALUE VALUES LESS THAN MAXVALUE);";
        /* TODO: Add checks and recoveries for when all expected partitions do not already exists.
         *     The above logic is not complete, failing to handle changing conditions.
         *     For example, if the `historicalPartitionCount` increased from 7 to 14, many historical
         *     partitions will not already exist, so we get one very large last partition. Many other
         *     scenarios exist, all resulting in mega-size partitions until it is eventually deleted.
         */

        // determine the name of the partition ready for dropping, the partition name format is `p<Ymd>`
        $partitionForDeletion = "p{$pastBoundaryDate->format(Constants::PARTITION_NAME_DATE_FORMAT)}";
        // get the list of all partitions for dropping, the one above and older ones if exist
        $partitionsForDeletion = $this->getPartitionsForDeletion($partitionForDeletion);

        if ($partitionsForDeletion) {
            $statements[] = "ALTER TABLE $this->tableName DROP PARTITION $partitionsForDeletion;";
        }

        foreach ($statements as $statement) {
            $this->comment('EXECUTING STATEMENT:');
            $this->info($statement);
            DB::statement($statement);
        }

        $this->comment('RESULTED TABLE STRUCTURE:');
        $this->info(DB::select(DB::raw("SHOW CREATE TABLE $this->tableName;"))[0]->{'Create Table'});

        return self::SUCCESS;
    }

    /**
     *  Checks all arguments and ensures that they are valid in order to partition the table
     *
     * @return void
     * @throws InvalidArgumentException if any argument passed to this command is invalid.
     *                                  This includes tables that are not in the expected
     *                                  state or that do not exist.
     */
    private function validateArguments(): void
    {
        $error = '';

        if (!$this->hasValidPeriods()) {
            $this->error(
                $error .= "The `historicPartitionCount` ($this->historicPartitionCount) and the`futurePartitionCount` "
                    . "($this->futurePartitionCount) both must be greater than " . self::MINIMUM_NUMBER_OF_PARTITIONS
                    . "\n"
            );
        }

        if (!Schema::hasTable($this->tableName)) {
            $this->error($error .= "Invalid table name entry. `$this->tableName` was not found.\n");
        }

        if (!$this->tableIsCorrectlyPartitioned()) {
            $this->error(
                $error .= "`$this->tableName` is not compatibly partitioned.  Did you use `table:partition-by-date`?\n"
            );
        }

        if ($error) {
            throw new InvalidArgumentException($error);
        }
    }

    /**
     * Check the validity of the periods.
     * Expected - integers greater than one.
     *
     * @return bool true if provided periods are meet the {@see self::MINIMUM_NUMBER_OF_PARTITIONS}, otherwise false
     *
     * TODO: Justify this logic - @danac
     */
    private function hasValidPeriods(): bool
    {
        foreach ([$this->futurePartitionCount, $this->historicPartitionCount] as $period) {
            if (
                filter_var($period, FILTER_VALIDATE_INT) === false
                || (int)$period < self::MINIMUM_NUMBER_OF_PARTITIONS
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Checks if the table is compatibly partitioned by querying its status
     *
     * @return bool true if the table is partitioned having a pMAXVALUE partition, otherwise false
     */
    private function tableIsCorrectlyPartitioned(): bool
    {
        $tableSchema = config('database.connections.mysql.database');
        $pMaxValuePartition = DB::select(
            DB::raw(
                "SELECT PARTITION_NAME as fallThroughPartition FROM INFORMATION_SCHEMA.PARTITIONS
                    WHERE TABLE_SCHEMA = '$tableSchema' AND TABLE_NAME = '$this->tableName'
                    AND PARTITION_NAME = 'pMAXVALUE';"
            )
        );

        return DbHelper::discoversTableIsAlreadyPartitioned($this->tableName) && $pMaxValuePartition;
    }

    /**
     * Get the list of partitions to be dropped by querying their names from the INFORMATION_SCHEMA table
     * and comparing them to the partition picked as suitable for deletion. The resulting partitions should be older
     * or equal to the matching one.
     *
     * @param string $partitionForDeletion the name of the last (newest) partition that meets the criteria for deletion
     *                                      - `historicPartitionCount` days past from the day of the command execution
     *
     * @return string                       comma separated list of partition names
     */
    private function getPartitionsForDeletion(string $partitionForDeletion): string
    {
        $tableSchema = config('database.connections.mysql.database');
        $partitionsForDeletionResult = DB::select(
            DB::raw(
                "SELECT GROUP_CONCAT(PARTITION_NAME) as partitions FROM INFORMATION_SCHEMA.PARTITIONS
                    WHERE TABLE_SCHEMA = '$tableSchema' AND TABLE_NAME = '$this->tableName'
                    AND 
                    (
                        PARTITION_NAME <= '$partitionForDeletion'
                        OR
                        (
                            PARTITION_NAME != 'pMAXVALUE' 
                            AND PARTITION_NAME NOT REGEXP '^p[0-9]{8}$'
                        )
                    );
                "
            )
        );

        return $partitionsForDeletionResult[0]?->partitions ?? '';
    }
}
