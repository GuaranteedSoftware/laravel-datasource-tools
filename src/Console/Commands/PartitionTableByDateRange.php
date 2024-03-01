<?php

namespace GuaranteedSoftware\Laravel\DatasourceTools\Console\Commands;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use GuaranteedSoftware\Laravel\DatasourceTools\Contracts\Constants;
use GuaranteedSoftware\Laravel\DatasourceTools\Helpers\DbHelper;
use GuaranteedSoftware\Laravel\DatasourceTools\Providers\DatasourceToolsServiceProvider;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * A command to split a table into partitions by dates across a range of dates
 *
 * Example usage:
 *
 * php artisan table:partition-by-date wms_requests 2023-09-25 2023-10-01 --created_at_index
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
 *   `created_at_indexed` date NOT NULL DEFAULT (curdate()),
 *   PRIMARY KEY (`id`,`created_date`),
 *   KEY `wms_requests_created_at_indexed_index` (`created_at_indexed`)
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
class PartitionTableByDateRange extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'table:partition-by-date
                            {tableName : The name of the table to partition}
                            {startDate : The starting partition date in the format of ' . Constants::MYSQL_DATE_FORMAT . ', typically, in the past.}
                            {endDate : The concluding partition date in the format of ' . Constants::MYSQL_DATE_FORMAT . ', typically, in the future.}
                            {--partitionColumnName=created_at_indexed : The name of the column to partition against}
                            {--blueprint= : A special option used internally when this command is invoked programmatically via migrations.  It should not be used via the CLI.}
                            {--databaseManagerStatements=  : A special option used internally when this command is invoked programmatically via migrations.  It should not be used via the CLI.}';

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
     * The starting partition date in the format of {@see Constants::MYSQL_DATE_FORMAT}
     * Typically, this date should be in the past
     *
     * @var string
     */
    private string $startDate = '';

    /**
     * The concluding partition date in the format of {@see Constants::MYSQL_DATE_FORMAT}
     * Typically, this date should be in the future
     *
     * @var string
     */
    private string $endDate = '';

    /**
     * The table blueprint used for manipulating schemas in the migration context.  If this value has been
     * set, then this command will operate in "blueprint" mode, performing actions against the blueprint instead
     * of generating and running migration files.
     *
     * @var ?Blueprint
     */
    private ?Blueprint $tableBlueprint;

    /**
     * A stack of statements to be executed against the table, used in the migration context. If a blueprint has been
     * set, then this command will operate in "blueprint" mode, will add statements to this queue instead
     * of generating and running migration files.  These statements are expected to be run by the caller after command
     * completion.
     *
     * @var array
     */
    private array $databaseManagerStatements = [];

    /**
     * Execute the console command to split a table into partitions
     *
     * @return int  exit codes: SUCCESS = 0, FAILURE = 1, INVALID = 2
     */
    public function handle(): int
    {
        $this->tableName = $this->argument('tableName');
        $this->startDate = $this->argument('startDate');
        $this->endDate = $this->argument('endDate');
        $this->partitionColumnName = $this->option('partitionColumnName');
        $this->tableBlueprint = $this->option('blueprint');
        $this->databaseManagerStatements = $this->option('$databaseManagerStatements');

        try {
            $this->validateArguments();
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->call('help', ['command_name' => 'table:partition-by-date', 'format' => 'raw']);
            return self::INVALID;
        }

        if (!Schema::hasColumn($this->tableName, $this->partitionColumnName)) {
            if ($this->tableBlueprint) {
                $this->addPartitionColumnToBlueprint();

                if (Schema::hasColumn($this->tableName, 'created_at')) {
                    $this->databaseManagerStatements[] = "UPDATE $this->tableName SET $this->partitionColumnName = date(created_at);";
                }
            } else {
                $this->makeAddPartitionColumnMigrationFile();
            }
        }

        if ($this->tableBlueprint) {
            $this->databaseManagerStatements[] = $this->getPartitionStatement();
        } else {
            $this->makeCreatePartitionsMigrationFile();

            Artisan::call('migrate');

            $this->comment('RESULTED TABLE STRUCTURE:');
            $this->info(DB::select(DB::raw("SHOW CREATE TABLE $this->tableName ;"))[0]->{'Create Table'});
        }

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

        if (!$this->isValidDateRange($this->startDate, $this->endDate)) {
            $this->error(
                $error .= "Invalid input date(s) !!! startDate: [$this->startDate], endDate: [$this->endDate]\n"
                    . "expected format: [" . Constants::MYSQL_DATE_FORMAT . "] where the startDate is before"
                    . " the endDate.\n"
            );
        }

        if (!Schema::hasTable($this->tableName)) {
            $this->error($error .= "Invalid table name entry. `$this->tableName` was not found.\n");
        }

        if (DbHelper::discoversTableIsAlreadyPartitioned($this->tableName)) {
            $this->error($error .= "Table `$this->tableName` has already been partitioned.\n");
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
                Carbon::createFromFormat(Constants::MYSQL_DATE_FORMAT, $date)
                    ->format(Constants::MYSQL_DATE_FORMAT) !== $date
            ) {
                return false;
            }
        }
        return $startDate <= $endDate;
    }

    /**
     * Add the schema definition to the table blueprint.  This is used when the macro `Blueprint::partitionByDateRange`
     * is invoked.
     *
     * @return void
     *
     * @see DatasourceToolsServiceProvider::boot()
     * @see file: laravel-datasource-tools/stubs/database/migrations/add_partition_column.php - must be kept synced to this
     */
    private function addPartitionColumnToBlueprint(): void
    {
        $this->tableBlueprint->date($this->partitionColumnName)->default(DB::raw('(CURRENT_DATE)'))->index();
        $this->tableBlueprint->unique(['id']);
        $this->tableBlueprint->dropPrimary(['id']);
        $this->tableBlueprint->primary(['id', $this->partitionColumnName]);
    }

    /**
     * Creates and returns the partition statement for this table
     *
     * @return string a single `ALTER TABLE` statement that makes the partitions
     */
    private function getPartitionStatement(): string {
        $period = CarbonPeriod::create($this->startDate, $this->endDate);

        $partitionStatements = "";
        foreach ($period as $date) {
            $partitionStatements .=
                "partition p{$date->format(Constants::PARTITION_NAME_DATE_FORMAT)} VALUES LESS THAN
                 (to_days('{$date->add('days', 1)->format(Constants::MYSQL_DATE_FORMAT)}')),\n";
        }

        return
            "ALTER TABLE $this->tableName
            PARTITION by range (to_days($this->partitionColumnName))
            (
              $partitionStatements
              partition pMAXVALUE VALUES LESS THAN MAXVALUE
            );";
    }

    /**
     * Adds the {@see self::$partitionColumnName} to the table {@see self::$tableName
     *
     * @return void
     */
    private function makeAddPartitionColumnMigrationFile(): void
    {
        $this->makeMigrationFile(
            'add_partition_column',
            [
                '{{tableName}}' => $this->tableName,
                '{{partitionColumnName}}' => $this->partitionColumnName,
            ]
        );
    }

    /**
     * Adds the date-delimited partitions to $this->{@see PartitionTableByDateRange::$tableName}
     * @return void
     */
    private function makeCreatePartitionsMigrationFile(): void
    {

        $this->makeMigrationFile(
            'create_partitions',
            [
                '{{tableName}}' => $this->tableName,
                '{{statement}}' => $this->getPartitionStatement(),
            ]
        );
    }

    /**
     * Creates a migration file and stores it in the Laravel `database/migrations/` folder
     *
     * @param string $stubFileBaseName the base name, (i.e. name without the extension), of the stub file
     *                                 located in this library's `stubs/database/migrations` folder
     * @param array $textReplacements an associative array of text replacements where the keys are the
     *                                text to be replaced in the stub files with the corresponding values
     *                                in the array.  By convention, the keys follow the format
     *                                `{{variableName}}`, but this is not a requirement.
     *
     * @return void
     */
    private function makeMigrationFile(string $stubFileBaseName, array $textReplacements): void
    {
        $stubFilePath = __DIR__ . "/../../../../../stubs/database/migrations/$stubFileBaseName.php";
        $migrationFileContent = file_get_contents($stubFilePath);

        foreach($textReplacements as $placeholder => $textReplacement) {
            $migrationFileContent = str_replace($placeholder, $textReplacement, $migrationFileContent);
        }

        $timestamp = date('Y_m_d_His');
        $migrationFilePath = database_path('migrations')  . "/{$timestamp}_{$stubFileBaseName}_to_$this->tableName.php";

        file_put_contents($migrationFilePath, $migrationFileContent);
    }
}
