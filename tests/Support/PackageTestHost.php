<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Support\Facades\Route;

/** A synthetic host: published files, caches and uploads never live under vendor or the checkout. */
final class PackageTestHost
{
    private static ?string $runDirectory = null;

    public static function directory(): string
    {
        if (self::$runDirectory === null) {
            self::$runDirectory = sys_get_temp_dir().'/laravel-admin-host-'.bin2hex(random_bytes(8));
            register_shutdown_function(function () {
                if (self::$runDirectory !== null && is_file(self::$runDirectory.'/.laravel-admin-test-host')) {
                    self::remove(self::$runDirectory);
                }
            });
        }

        return self::$runDirectory;
    }

    public static function assertDisposableEnvironment(): void
    {
        $driver = getenv('DB_CONNECTION');
        $host = getenv('DB_HOST');
        if (getenv('APP_ENV') !== 'testing'
            || getenv('LARAVEL_ADMIN_DISPOSABLE_TESTS') !== '1'
            || !is_file('/run/laravel-admin-disposable-tests')
            || !in_array($driver, ['pgsql', 'mysql'], true)
            || $host !== ($driver === 'pgsql' ? 'postgres' : 'mysql')
            || getenv('DB_DATABASE') !== 'laravel_admin_test'
            || getenv('DB_USERNAME') !== 'laravel_admin_test') {
            throw new RuntimeException('Refusing database setup: use scripts/test-container with its disposable test services.');
        }
    }

    public static function create(string $directory): Application
    {
        self::assertDisposableEnvironment();
        foreach (['app', 'bootstrap/cache', 'config', 'database/migrations', 'public/uploads', 'resources/views', 'routes', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'lang'] as $path) {
            if (!is_dir($directory.'/'.$path)) {
                mkdir($directory.'/'.$path, 0777, true);
            }
        }
        file_put_contents($directory.'/.laravel-admin-test-host', 'synthetic disposable fixture');
        file_put_contents($directory.'/composer.json', json_encode(['name' => 'encore/admin-test-host', 'require' => ['laravel/framework' => '^'.Illuminate\Foundation\Application::VERSION], 'autoload' => ['psr-4' => ['App\\' => 'app/']]]));
        // The real package autoloader remains read-only; generated host classes have explicit local registration.
        $loader = new \Composer\Autoload\ClassLoader();
        $loader->addPsr4('App\\', $directory.'/app');
        $loader->register(true);
        require_once __DIR__.'/../Fixtures/Controller.php';
        $app = Application::configure(basePath: $directory)
            ->withProviders([], withBootstrapProviders: false)
            ->withRouting(using: function () {
                Route::middleware('web')->get('/', fn () => '<html><body>Laravel test host</body></html>');
            })
            ->withMiddleware(fn ($middleware) => null)
            ->withExceptions(fn ($exceptions) => null)
            ->create();
        $app->make(\Illuminate\Foundation\PackageManifest::class)->vendorPath = dirname(\Composer\InstalledVersions::getInstallPath('laravel/framework'), 2);
        // Provider/service manifests must remain in the disposable bootstrap/cache directory.
        $app->beforeBootstrapping(RegisterProviders::class, function ($app) {
            $config = $app['config'];
            $driver = getenv('DB_CONNECTION');
            $config->set('app.env', 'testing');
            $config->set('app.debug', true);
            $config->set('app.key', 'base64:'.base64_encode(str_repeat('t', 32)));
            $config->set('app.locale', 'en');
            $config->set('cache.default', 'array');
            $config->set('session.driver', 'array');
            $config->set('session.serialization', 'php');
            $config->set('database.default', $driver);
            $config->set('database.connections.'.$driver.'.url', null);
            foreach (['host' => 'DB_HOST', 'port' => 'DB_PORT', 'database' => 'DB_DATABASE', 'username' => 'DB_USERNAME', 'password' => 'DB_PASSWORD'] as $key => $env) {
                $config->set('database.connections.'.$driver.'.'.$key, getenv($env));
            }
            $config->set('filesystems', [
                'default' => 'admin',
                'disks' => ['admin' => ['driver' => 'local', 'root' => public_path('uploads'), 'visibility' => 'public', 'url' => 'http://localhost:8000/uploads/']],
            ]);
            // Preserve the existing suite's exact admin overrides (including legacy action rendering).
            $config->set('admin', require __DIR__.'/../config/admin.php');
            $config->set('admin.auth.guards.admin.driver', 'session');
            foreach (\Illuminate\Support\Arr::dot($config->get('admin.auth'), 'auth.') as $key => $value) {
                $config->set($key, $value);
            }
            $config->set('app.aliases.Admin', \Encore\Admin\Facades\Admin::class);
        });
        $app->booting(function () {
            \Illuminate\Foundation\AliasLoader::getInstance()->alias('Admin', \Encore\Admin\Facades\Admin::class);
        });
        $app->make(Kernel::class)->bootstrap();
        $app->register(\Encore\Admin\AdminServiceProvider::class);

        return $app;
    }

    public static function remove(string $directory): void
    {
        $real = realpath($directory);
        if (!$real || !str_starts_with($real, rtrim(sys_get_temp_dir(), '/').'/laravel-admin-host-') || !is_file($real.'/.laravel-admin-test-host')) {
            throw new RuntimeException('Refusing to remove a directory not owned by this test host.');
        }
        (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($real);
    }
}
