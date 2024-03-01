# laravel-datasource-tools
Tools for extending datasource functionality in Laravel

## Installation

1. Install the library with `composer`
> composer require guaranteed/laravel-datasource-tools

2. Datasource Tools utilizes Laravel's auto-discovery feature to automatically register 
   the commands in your Laravel application.


3. Running each command will automatically install and run migration files stored in the
   `database/migrations` folder.  This is done to allow you to use rollbacks within the
   standard Laravel workflow.
