<?php

try {
    $host = $argv[1] ?? '/host';
    require $host.'/vendor/autoload.php';
    $framework = realpath(Composer\InstalledVersions::getInstallPath('laravel/framework'));
    if ($framework !== realpath($host.'/vendor/laravel/framework')) throw new RuntimeException('Framework vendor graph leaked from another host');
    $app = require $host.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (!$app->providerIsLoaded(Encore\Admin\AdminServiceProvider::class)) throw new RuntimeException('Composer package discovery failed');
    if ($app['config']->get('admin.route.prefix') !== 'admin') throw new RuntimeException('Published package config did not load');
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $request = Illuminate\Http\Request::create('/dependency-probe');
    $response = $kernel->handle($request);
    if ($response->getStatusCode() !== 200 || !str_contains($response->getContent(), '<main>Laravel 13 host</main>')) {
        throw new RuntimeException('Native host route/Blade request failed: '.$response->getStatusCode().' '.$response->getContent());
    }
    $kernel->terminate($request, $response);
    echo json_encode(['php'=>PHP_VERSION,'framework'=>Illuminate\Foundation\Application::VERSION,'framework_path'=>$framework,'package_path'=>Composer\InstalledVersions::getInstallPath('encore/laravel-admin'),'vendor_path'=>$app->make(Illuminate\Foundation\PackageManifest::class)->vendorPath,'provider_discovered'=>true,'published_config_loaded'=>true,'http_status'=>200,'blade_rendered'=>true], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
