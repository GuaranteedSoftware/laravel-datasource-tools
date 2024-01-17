# laravel-datasource-tools
Tools for extending datasource functionality in Laravel

## Installation

1. Install the library with `composer`
> composer require guaranteed/laravel-datasource-tools

2. Register the commands in your Laravel application.  In a default installation, 
   this will be located in the `App/Console/Kernel::command` method.  Add the following
   line of code to the method:
```php
$this->load(base_path('vendor/guaranteed/laravel-datasource-tools/src/GuaranteedSoftware/LaravelDatasourceTools/Console/Commands'));
```
