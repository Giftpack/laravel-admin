<?php
// Materialize writable hosts from the versioned manifest/lock pairs.
$lane = $argv[1] ?? '';
if (!in_array($lane, ['current', 'lowest', 'optional', 'optional-lowest', 'excel', 'excel-lowest'], true)) {
    throw new RuntimeException('Expected current, lowest, optional[-lowest] or excel[-lowest]');
}
$root = dirname(__DIR__);
$source = "$root/tests/Fixtures/dependencies/host-$lane";
$directory = $argv[2] ?? "$root/.scratch/php85-laravel13-upgrade/dependencies/host-$lane";
if (isset($argv[2]) && $argv[2] !== '/host') {
    throw new RuntimeException('The optional container output directory must be /host');
}
foreach (['composer.json', 'composer.lock'] as $name) {
    if (!is_file("$source/$name")) {
        throw new RuntimeException("Missing versioned dependency fixture: host-$lane/$name");
    }
    json_decode(file_get_contents("$source/$name"), true, flags: JSON_THROW_ON_ERROR);
}
foreach (['bootstrap/cache', 'config', 'routes', 'resources/views', 'storage/framework/views', 'storage/logs'] as $path) {
    if (!is_dir("$directory/$path") && !mkdir("$directory/$path", 0777, true) && !is_dir("$directory/$path")) {
        throw new RuntimeException("Could not create dependency host directory: $path");
    }
}
foreach (['composer.json', 'composer.lock'] as $name) {
    if (!copy("$source/$name", "$directory/$name")) {
        throw new RuntimeException("Could not materialize dependency host $name");
    }
}
file_put_contents("$directory/bootstrap/app.php", <<<'BOOT'
<?php
return Illuminate\Foundation\Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php')
    ->withMiddleware(fn ($middleware) => null)
    ->withExceptions(fn ($exceptions) => null)
    ->create();
BOOT);
file_put_contents("$directory/config/app.php", "<?php return ['env'=>'testing','key'=>'base64:".base64_encode(str_repeat('h',32))."','cipher'=>'AES-256-CBC'];\n");
copy("$root/config/admin.php", "$directory/config/admin.php");
file_put_contents("$directory/config/cache.php", "<?php return ['default'=>'array','stores'=>['array'=>['driver'=>'array']]];\n");
file_put_contents("$directory/config/session.php", "<?php return ['driver'=>'array','serialization'=>'php'];\n");
file_put_contents("$directory/routes/web.php", "<?php Illuminate\\Support\\Facades\\Route::get('/dependency-probe', fn () => view('dependency-probe', ['value'=>'Laravel 13 host']));\n");
file_put_contents("$directory/resources/views/dependency-probe.blade.php", '<main>{{ $value }}</main>');
echo $directory."\n";
