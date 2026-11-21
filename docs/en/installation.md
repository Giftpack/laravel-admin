# Installation — 2.0 candidate

This is an unpublished major-version candidate. A release/tag has not been created. The commands below target a future approved `^2.0` release; for local review use this checkout as a Composer path repository with an explicit `2.0.0` version override, as the isolated fixtures do.

Required: PHP `~8.5.13` (8.5.13 tested), Laravel `^13.40` (13.40.0 and 13.43.0 tested), Symfony 7.4 components at the floors in `composer.json`, and PostgreSQL 15.14 or MySQL 8.4.13. No other engine/framework/Symfony line is certified. Install PDO for the chosen engine, Fileinfo, mbstring and DOM/XML; image transforms need GD/EXIF and the optional Image package, XLSX needs ZIP and its optional package.

On a **fresh** Laravel 13 host, configure the database first:

```sh
composer require encore/laravel-admin:^2.0 --with-all-dependencies
php artisan vendor:publish --tag=laravel-admin-config
php artisan vendor:publish --tag=laravel-admin-migrations
php artisan vendor:publish --tag=laravel-admin-assets
php artisan vendor:publish --tag=laravel-admin-lang
php artisan admin:install
```

Review `config/admin.php` before installation to choose models, connection/table names, prefix/domain, guard and upload disk. `admin:install` runs the published migrations; the fresh synthetic default account is `admin` / `admin`. Change it before exposing the host. Generated application code is in `app/Admin`; assets are in `public/vendor/laravel-admin`. Views may be published separately with `--tag=laravel-admin-views` when you intend to maintain overrides.

Existing installations must follow [Upgrade](upgrade.md). Do not regenerate their admin directory or reseed their records. Normal publication preserves existing files; forced publication overwrites them, including custom config/views/assets.

Optional tested versions: Intervention Image 3.11.0/3.11.10; Maatwebsite Excel 4.0.7 with PhpSpreadsheet 5.11.0; Mews Captcha 3.5.1; Spatie Eloquent Sortable 5.0.1; Matthias Mullie Minify 1.3.76. These remain optional. Plain file/image uploads work without Intervention; transforms/thumbnails produce a controlled dependency error. External README extensions, live cloud disks and historical MongoDB integration remain unresolved prerequisites, not tested support or accepted removals.

Development checks use tagged BrowserKit 7.3.0, PHPUnit 12.5.8/12.5.45 and Mockery 1.6.15. The old transitive Mockery 1.0 spy generator fails PHP 8.5; the explicit development floor excludes it. Consumer runtime dependencies do not install development tools. See [test instructions](../../tests/README.md).
