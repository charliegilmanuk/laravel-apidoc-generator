# Laravel API Documentation Generator

> Fork of `mpociot/laravel-apidoc-generator` to add Vuepress export capabilities.  I have not written tests but am using this in a production project.
>
> Please see config for new options, please note you need to make the `.vuepress/config.js` file yourself and run the Vuepress compiler.

Automatically generate your API documentation from your existing Laravel/Lumen/[Dingo](https://github.com/dingo/api) routes. [Here's what the output looks like](http://marcelpociot.de/whiteboard/).

`php artisan apidoc:generate`


## Installation
PHP 7 and Laravel 5.5 or higher are required.

```sh
composer require mpociot/laravel-apidoc-generator
```

### Laravel
Publish the config file by running:

```bash
php artisan vendor:publish --provider="Mpociot\ApiDoc\ApiDocGeneratorServiceProvider" --tag=apidoc-config
```

This will create an `apidoc.php` file in your `config` folder.

### Lumen
- Register the service provider in your `bootstrap/app.php`:

```php
$app->register(\Mpociot\ApiDoc\ApiDocGeneratorServiceProvider::class);
```

- Copy the config file from `vendor/mpociot/laravel-apidoc-generator/config/apidoc.php` to your project as `config/apidoc.php`. Then add to your `bootstrap/app.php`:

```php
$app->configure('apidoc');
```

## Documentation
Check out the documentation at [ReadTheDocs](http://laravel-apidoc-generator.readthedocs.io).

### License

The Laravel API Documentation Generator is free software licensed under the MIT license.
