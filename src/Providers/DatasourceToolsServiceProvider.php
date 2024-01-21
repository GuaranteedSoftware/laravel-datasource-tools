<?php

namespace GuaranteedSoftware\LaravelDatasourceTools\Providers;

use GuaranteedSoftware\LaravelDatasourceTools\Console\Commands\PartitionTableByDate;
use GuaranteedSoftware\LaravelDatasourceTools\Console\Commands\UpdateTablePartitions;
use Illuminate\Support\ServiceProvider;

/**
 * Bootstrap class auto-discovered by Laravel.  It registers the datasource commands.
 */
class DatasourceToolsServiceProvider extends ServiceProvider
{

    /**
     * Bootstrap any application services.  This differs from the register
     * method by executing after all other service provider register methods
     * have executed, including the complete Laravel Framework.
     *
     * Any universal bootstrapping needed that require the entire system to be
     * available should be accomplished here
     *
     * Here, we use this to register our custom commands.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands(
                [
                    PartitionTableByDate::class,
                    UpdateTablePartitions::class
                ]
            );
        }
    }
}
