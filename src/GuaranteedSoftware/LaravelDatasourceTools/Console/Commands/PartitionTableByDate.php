<?php

namespace GuaranteedSoftware\LaravelDatasoureTools\Console\Commands;

use Illuminate\Console\Command;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * A command to split a table into partitions
 *
 * Example usage:
 *
 * php artisan table:partition-by-date wms_requests created_at_index 2023-09-25 2023-10-01
 *
 * EXECUTING STATEMENT:
 * ALTER TABLE wms_requests
 *   PARTITION by range (to_days(created_date))
 *   (
 *     partition p20230925 VALUES LESS THAN (to_days('2023-09-26')),
 *     partition p20230926 VALUES LESS THAN (to_days('2023-09-27')),
 *     partition p20230927 VALUES LESS THAN (to_days('2023-09-28')),
 *     partition p20230928 VALUES LESS THAN (to_days('2023-09-29')),
 *     partition p20230929 VALUES LESS THAN (to_days('2023-09-30')),
 *     partition p20230930 VALUES LESS THAN (to_days('2023-10-01')),
 *     partition p20231001 VALUES LESS THAN (to_days('2023-10-02')),
 *     partition pMAXVALUE VALUES LESS THAN MAXVALUE
 *   );
 *
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
 * (PARTITION p20230925 VALUES LESS THAN (739154) ENGINE = InnoDB,
 *  PARTITION p20230926 VALUES LESS THAN (739155) ENGINE = InnoDB,
 *  PARTITION p20230927 VALUES LESS THAN (739156) ENGINE = InnoDB,
 *  PARTITION p20230928 VALUES LESS THAN (739157) ENGINE = InnoDB,
 *  PARTITION p20230929 VALUES LESS THAN (739158) ENGINE = InnoDB,
 *  PARTITION p20230930 VALUES LESS THAN (739159) ENGINE = InnoDB,
 *  PARTITION p20231001 VALUES LESS THAN (739160) ENGINE = InnoDB,
 *  PARTITION pMAXVALUE VALUES LESS THAN MAXVALUE ENGINE = InnoDB)
 */
class PartitionTable extends Command
{
    /**
     * MySql date format, the native representation of the MySql date type
     * that is used for created_date column comparison when creating / deleting partitions.
     *
     * @var string
     */
    public const MYSQL_DATE_FORMAT = 'Y-m-d';

    /**
     * Having hyphens (-) in partition names is against the SQL standard, perhaps it can be used if quoted correctly,
     * but not recommended. So this format is used. The partition name can not start with a digit so the chosen
     * format is `p<Ymd>`. The date is included in the partition name, so we can later delete old partitions.
     * @var string
     */
    public const PARTITION_NAME_DATE_FORMAT = 'Ymd';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'table:partition-by-date
                            {tableName : The name of the table to partition}
                            {partitionColumnName : The name of the column to partition against}
                            {startDate : The starting partition date in the format of ' . self::MYSQL_DATE_FORMAT . '}
                            {endDate : The concluding partition date in the format of ' . self::MYSQL_DATE_FORMAT . '}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Partition provided table by range on created_date column';

    /**
     * The name of the table to partition
     *
     * @var string
     */
    private string $tableName= '';

    /**
     * The name of the column to partition against
     *
     * @var string
     */
    private string $partitionColumnName = '';

    /**
     * The starting partition date in the format of {@see self::MYSQL_DATE_FORMAT}
     *
     * @var string
     */
    private string $startDate = '';

    /**
     * The concluding partition date in the format of {@see self::MYSQL_DATE_FORMAT}
     *
     * @var string
     */
    private string $endDate = '';

    /**
     * Execute the console command to split a table into partitions
     *
     * @return int  exit codes: SUCCESS = 0, FAILURE = 1, INVALID = 2
     */
    public function handle(): int
    {
        $this->tableName = $this->argument('tableName');
        $this->partitionColumnName = $this->argument('partitionColumnName');
        $this->startDate = $this->argument('startDate');
        $this->endDate = $this->argument('endDate');

        try {
            $this->validateArguments();
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->call('help', ['command_name' => 'table:partition-by-date', 'format' => 'raw']);
            return self::INVALID;
        }

        if (!Schema::hasColumn($this->tableName, $this->partitionColumnName)) {
            $this->doAddPartitionColumnMigration();
        }

        $period = CarbonPeriod::create($this->startDate, $this->endDate);

        $partitionStatements = "";
        foreach ($period as $date) {
            $partitionStatements .=
                "partition p{$date->format(self::PARTITION_NAME_DATE_FORMAT)} VALUES LESS THAN
                 (to_days('{$date->add('days', 1)->format(self::MYSQL_DATE_FORMAT)}')),\n";
        }

        $statement =
            "ALTER TABLE $this->tableName
            PARTITION by range (to_days($this->partitionColumnName))
            (
              $partitionStatements
              partition pMAXVALUE VALUES LESS THAN MAXVALUE
            );";

        $this->comment('EXECUTING STATEMENT:');
        $this->info($statement);

        DB::statement($statement);

        $this->comment('RESULTED TABLE STRUCTURE:');
        $this->info(DB::select(DB::raw("SHOW CREATE TABLE $this->tableName ;"))[0]->{'Create Table'});

        return self::SUCCESS;
    }

    /**
     * Check if the passed dates (as strings) satisfy the required date format and
     * if the $startDate is less or equal to the $endDate
     *
     * @param string $startDate start of the date interval
     * @param string $endDate   end of the date interval
     *
     * @return bool             true if date range is valid, otherwise false
     */
    private function isValidDateRange(string $startDate, string $endDate): bool
    {
        foreach ([$startDate, $endDate] as $date) {
            if (
                Carbon::createFromFormat(self::MYSQL_DATE_FORMAT, $date)
                    ->format(self::MYSQL_DATE_FORMAT) !== $date
            ) {
                return false;
            }
        }
        return $startDate <= $endDate;
    }

    /**
     *  Checks all input and ensures that they are valid in order to partition the table
     *
     * @return void
     * @throws InvalidArgumentException if any argument past to this command is invalid.
     *                                  this includes tables that are not in the expected
     *                                  state or that do not exist.
     */
    private function validateArguments(): void
    {
        $error = '';

        if (!$this->isValidDateRange($this->startDate, $this->endDate)) {
            $this->error(
                $error .= "Invalid input date(s) !!! startDate: [$this->startDate], endDate: [$this->endDate]\n"
                    . "expected format: [" . self::MYSQL_DATE_FORMAT . "] where the startDate is before"
                    . " the endDate.\n"
            );
        }

        if (!Schema::hasTable($this->tableName)) {
            $this->error($error .= "Invalid table name entry. `$this->tableName` was not found.\n");
        }

        if (!$this->partitionColumnName) {
            $this->error($error .= "Argument partitionColumnName is required!!!\n");
        }

        $idDefinition = '';
        if (
            !Schema::hasColumn($this->tableName, 'id')
            || (
                !str_starts_with(
                    $idDefinition = Schema::getColumnType($this->tableName, 'id', true),
                    'int'
                )
                && !str_starts_with($idDefinition, 'bigint')
            )
            || !str_contains($idDefinition, 'primary key')
        ) {
            $this->error(
                $error .= "`$this->tableName` is required to have an `id` field of type `int` "
                    . "or `bigint` and must make up the primary key. The current column definition "
                    . "does not meet that criteria:\n\n"
                    . "$idDefinition\n"
            );
        }

        if (
            Schema::hasColumn($this->tableName, $this->partitionColumnName)
            && (
                !str_starts_with(
                    $partitionColumnDefinition = Schema::getColumnType($this->tableName, $this->partitionColumnName, true),
                    'date'
                )
                || !str_contains($partitionColumnDefinition, 'primary key')
                || !str_contains($idDefinition, 'unique')
            )
        ) {
            $this->error(
                $error .= "`$this->partitionColumnName` is required to be of type `date` "
                    . "and must make up the primary key along with a `unique` `id` field. "
                    . "The current column definitions do not meet that criteria\n\n:"
                    . "$partitionColumnDefinition\n"
                    . "$idDefinition\n"
            );
        }

        if ($error) {
            throw new InvalidArgumentException($error);
        }
    }

    /**
     * Adds the {@see self::$partitionColumnName} to the table {@see self::$tableName
     *
     * @return void
     */
    private function doAddPartitionColumnMigration() {

    }
}