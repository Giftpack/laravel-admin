<?php
/** Native Composer-discovered Laravel host, own /host/vendor; no root autoloader. */
try {
    require '/host/vendor/autoload.php';
    require_once '/application/tests/Support/PackageTestHost.php';
    PackageTestHost::assertDisposableEnvironment();
    function check($condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
    $directory = sys_get_temp_dir().'/laravel-admin-host-lifecycle-'.bin2hex(random_bytes(6));
    foreach (['app', 'bootstrap/cache', 'config', 'database/migrations', 'public', 'resources/views/vendor/admin', 'routes', 'storage/framework/views', 'storage/logs', 'lang'] as $path) mkdir($directory.'/'.$path, 0777, true);
    file_put_contents($directory.'/.laravel-admin-test-host', 'native lifecycle');
    symlink('/host/vendor', $directory.'/vendor');
    file_put_contents($directory.'/composer.json', json_encode(['name'=>'encore/lifecycle-host','autoload'=>['psr-4'=>['App\\'=>'app/']]]));
    $bootstrap = str_replace('return Illuminate', '$app = Illuminate', file_get_contents('/host/bootstrap/app.php'));
    $bootstrap .= <<<'HOST'

$app->booted(function () {
    app('blade.compiler')->directive('fixture', fn () => "<?php echo 'host-directive'; ?>");
    app('blade.compiler')->stringable(stdClass::class, fn ($value) => $value->text);
});
return $app;
HOST;
    file_put_contents($directory.'/bootstrap/app.php', $bootstrap);
    file_put_contents($directory.'/artisan', '<?php require __DIR__."/vendor/autoload.php"; $loader = new Composer\\Autoload\\ClassLoader(); $loader->addPsr4("App\\\\", __DIR__."/app"); $loader->register(true); $app = require __DIR__."/bootstrap/app.php"; exit($app->handleCommand(new Symfony\\Component\\Console\\Input\\ArgvInput));');
    file_put_contents($directory.'/config/app.php', "<?php return ['env'=>'testing','key'=>'base64:".base64_encode(str_repeat('x',32))."','cipher'=>'AES-256-CBC','debug'=>true];");
    file_put_contents($directory.'/config/cache.php', "<?php return ['default'=>'array','stores'=>['array'=>['driver'=>'array']]];");
    file_put_contents($directory.'/config/session.php', "<?php return ['driver'=>'array'];");
    file_put_contents($directory.'/config/auth.php', "<?php return ['defaults'=>['guard'=>'web','passwords'=>'users'],'guards'=>['web'=>['driver'=>'session','provider'=>'users']],'providers'=>['users'=>['driver'=>'eloquent','model'=>stdClass::class]]];");
    $connection = ['driver'=>getenv('DB_CONNECTION'),'host'=>getenv('DB_HOST'),'port'=>getenv('DB_PORT'),'database'=>getenv('DB_DATABASE'),'username'=>getenv('DB_USERNAME'),'password'=>getenv('DB_PASSWORD'),'charset'=>getenv('DB_CONNECTION')==='mysql'?'utf8mb4':'utf8','prefix'=>'','schema'=>'public'];
    $adminConnection = $connection;
    $adminConnection['prefix'] = 'admin_conn_';
    file_put_contents($directory.'/config/database.php', '<?php return '.var_export(['default'=>'host','connections'=>['host'=>$connection,'admin_fixture'=>$adminConnection]],true).';');
    // Initially no admin.php: assert unpublished defaults and discovery before publishing.
    file_put_contents($directory.'/routes/web.php', '<?php Illuminate\\Support\\Facades\\Route::get("/host", fn () => "host")->name("host"); Illuminate\\Support\\Facades\\Route::content("/host-macro", "native macro"); Illuminate\\Support\\Facades\\Route::component("/host-component", "admin::probe");');
    $app = require $directory.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    check($app->providerIsLoaded(Encore\Admin\AdminServiceProvider::class), 'Provider discovery');
    check(class_exists('Admin') && function_exists('admin_path'), 'Facade/helper discovery');
    check(config('admin.route.prefix') === 'admin' && config('auth.guards.admin.driver') === 'session', 'Unpublished defaults/auth');
    check(config('auth.defaults.guard') === 'web' && config('auth.guards.web.provider') === 'users', 'Host auth preservation');
    $run = function (array $args, string $input = '', int $expectedExit = 0) use ($directory): string {
        $pipes = [];
        $process = proc_open(array_merge([PHP_BINARY, $directory.'/artisan'], $args), [['pipe','r'],['pipe','w'],['pipe','w']], $pipes, $directory);
        fwrite($pipes[0], $input); fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $exit = proc_close($process);
        check($exit === $expectedExit, implode(' ', $args).' failed '.$exit.' '.$out.$err);
        return $out.$err;
    };
    foreach (['config','views','lang','migrations','assets'] as $tag) $run(['vendor:publish','--tag=laravel-admin-'.$tag,'--no-interaction']);
    foreach (['config/admin.php','resources/views/vendor/admin/pagination.blade.php','lang/en/admin.php','database/migrations/2016_01_04_173148_create_admin_tables.php','public/vendor/laravel-admin/AdminLTE/dist/css/AdminLTE.min.css'] as $path) check(is_file($directory.'/'.$path), 'Published '.$path);
    $overrides = ['https'=>true,'directory'=>$directory.'/app/Backoffice','bootstrap'=>$directory.'/app/Backoffice/bootstrap.php','route'=>['prefix'=>'control','domain'=>'admin.example.test','name_prefix'=>'backoffice','namespace'=>'App\\Backoffice\\Controllers','middleware'=>['web']], 'auth'=>['guard'=>'backoffice','guards'=>['backoffice'=>['driver'=>'session','provider'=>'backoffice']],'providers'=>['backoffice'=>['driver'=>'eloquent','model'=>Encore\Admin\Auth\Database\Administrator::class]]], 'database'=>['connection'=>'admin_fixture']];
    foreach (['users','roles','permissions','menu','operation_log','user_permissions','role_users','role_permissions','role_menu'] as $table) $overrides['database'][$table.'_table'] = 'fixture_'.$table;
    file_put_contents($directory.'/config/admin.php', '<?php return '.var_export($overrides,true).';');
    $configHash = hash_file('sha256',$directory.'/config/admin.php');
    $broken = $directory.'/database/migrations/2099_01_01_000000_failure.php';
    file_put_contents($broken, '<?php return new class extends Illuminate\\Database\\Migrations\\Migration { public function up() { throw new RuntimeException("Expected fixture migration failure"); } };');
    $run(['admin:install','--no-interaction'], '', 1);
    check(!is_dir($directory.'/app/Backoffice'), 'Migration failure stopped file installation');
    unlink($broken);
    $run(['admin:install','--no-interaction']);
    $db = $app['db']->connection('admin_fixture');
    check($db->table('fixture_users')->count() === 1, 'Fresh seed');
    check(!$app['db']->connection('host')->getSchemaBuilder()->hasTable('fixture_users'), 'Selected prefixed connection');
    $business = $app['db']->connection('host');
    $business->getSchemaBuilder()->create('business_records', fn ($table) => $table->string('value'));
    $business->table('business_records')->insert(['value'=>'preserve']);
    $password = password_hash('changed-password', PASSWORD_BCRYPT);
    $db->table('fixture_users')->where('username','admin')->update(['password'=>$password]);
    file_put_contents($directory.'/app/Backoffice/Controllers/CustomController.php', '<?php // host-owned');
    file_put_contents($directory.'/app/Backoffice/bootstrap.php', '<?php // customized bootstrap');
    $run(['admin:install','--no-interaction']);
    check($db->table('fixture_users')->value('password') === $password, 'Repeat password preserved');
    $db->table('fixture_users')->delete();
    $roles = $db->table('fixture_roles')->get()->toJson();
    $run(['admin:install','--no-interaction']);
    check($db->table('fixture_users')->count() === 0 && $db->table('fixture_roles')->get()->toJson() === $roles, 'Partial installation not reseeded');
    $asset = $directory.'/public/vendor/laravel-admin/AdminLTE/dist/css/AdminLTE.min.css';
    file_put_contents($asset, 'custom asset');
    file_put_contents($directory.'/public/business.txt', 'business file');
    $run(['admin:publish','--no-interaction']);
    check(file_get_contents($asset) === 'custom asset', 'Publish preserved customized asset');
    check(hash_file('sha256',$directory.'/config/admin.php') === $configHash, 'Publishing preserved host config');
    $customConfig = file_get_contents($directory.'/config/admin.php');
    $run(['admin:publish','--force','--no-interaction']);
    check(hash_file('sha256',$directory.'/config/admin.php') === hash_file('sha256','/application/config/admin.php') && file_get_contents($asset) !== 'custom asset', 'Explicit force overwrote published files');
    file_put_contents($directory.'/config/admin.php', $customConfig);
    file_put_contents($asset, 'custom asset');
    // Native generated routes/controllers must autoload from the actual host app, not /host/app.
    file_put_contents($directory.'/probe.php', <<<'PROBE'
<?php
try {
putenv('APP_RUNNING_IN_CONSOLE=false');
require __DIR__.'/vendor/autoload.php';
$loader = new Composer\Autoload\ClassLoader(); $loader->addPsr4('App\\', __DIR__.'/app'); $loader->register(true);
$app = require __DIR__.'/bootstrap/app.php';
$app->instance('request', Illuminate\Http\Request::create($argv[1] ?? 'http://admin.example.test/control/auth/login'));
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
$expectedHttps = !isset($argv[1]);
if (str_starts_with(url('/host'), 'https://') !== $expectedHttps) throw new RuntimeException('HTTPS boundary');
$hostUrl = url('/host');
if (admin_url('auth/login') !== 'https://admin.example.test/control/auth/login') throw new RuntimeException('Admin URL domain');
if (url('/host') !== $hostUrl) throw new RuntimeException('Admin URL changed host origin');
$router = app('router');
$match = $router->getRoutes()->match(Illuminate\Http\Request::create('http://admin.example.test/control/auth/login'));
if ($match->getName() !== 'backoffice.login' || $match->middleware() !== ['web']) throw new RuntimeException('Configured routing/middleware');
try { $router->getRoutes()->match(Illuminate\Http\Request::create('http://other.example.test/control/auth/login')); throw new RuntimeException('Domain leak'); } catch (Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {}
if (admin_get_route('login') !== 'backoffice.login' || config('admin.route.namespace') !== 'App\\Backoffice\\Controllers' || config('admin.database.users_model') !== Encore\Admin\Auth\Database\Administrator::class || config('auth.defaults.guard') !== 'web' || config('auth.guards.backoffice.provider') !== 'backoffice') throw new RuntimeException('Config merge/guard');
if (!Illuminate\Routing\Router::hasMacro('content') || !Illuminate\Routing\Router::hasMacro('component')) throw new RuntimeException('Macros missing');
foreach (['/host-macro','/host-component'] as $macro) if (!$router->getRoutes()->match(Illuminate\Http\Request::create('http://other.example.test'.$macro))->run() instanceof Encore\Admin\Layout\Content) throw new RuntimeException('Macro execution');
if (Illuminate\Pagination\Paginator::$defaultView !== 'pagination::tailwind') throw new RuntimeException('Host pagination changed');
if (!Admin::grid(new Encore\Admin\Auth\Database\Administrator(), fn ($grid) => $grid->column("username")) instanceof Encore\Admin\Grid || !Admin::form(new Encore\Admin\Auth\Database\Administrator(), fn ($form) => $form->text("username")) instanceof Encore\Admin\Form || !Admin::show(new Encore\Admin\Auth\Database\Administrator()) instanceof Encore\Admin\Show) throw new RuntimeException('Builders');
if (!str_contains((new Illuminate\Pagination\LengthAwarePaginator([],30,10))->render('admin::pagination'), 'pagination')) throw new RuntimeException('Pagination');
if (view('escaping', ['value'=>'&amp;'])->render() !== '&amp;amp;' || view('admin::escaping', ['value'=>'&amp;'])->render() !== '&amp;' || view('escaping', ['value'=>'&amp;'])->render() !== '&amp;amp;') throw new RuntimeException('Host/admin escaping isolation');
$object = (object) ['text'=>'&amp;'];
if (trim(view('extensions', compact('object'))->render()) !== 'host-directive &amp;amp;' || trim(view('admin::extensions', compact('object'))->render()) !== 'host-directive &amp;' || view('vendor.admin-sibling.escaping', ['value'=>'&amp;'])->render() !== '&amp;amp;') throw new RuntimeException('Compiler extensions/path boundary');
$compiler = app('view.engine.resolver')->resolve('blade')->getCompiler();
$adminPath = app('view')->getFinder()->find('admin::escaping');
$hostPath = app('view')->getFinder()->find('escaping');
if ($compiler->getCompiledPath($hostPath) !== app('blade.compiler')->getCompiledPath($hostPath) || $compiler->getCompiledPath($adminPath) === app('blade.compiler')->getCompiledPath($adminPath) || !str_starts_with($compiler->getCompiledPath($adminPath), config('view.compiled').'/')) throw new RuntimeException('Compiler cache paths');
if (trim(view('admin::probe')->render()) !== 'host-view') throw new RuntimeException('View override');
echo json_encode(['route'=>$match->getName(),'domain'=>$match->getDomain(),'middleware'=>$match->middleware(),'host_route'=>$router->getRoutes()->getByName('host')->uri(),'view'=>'host-view','vendor'=>realpath(Composer\InstalledVersions::getInstallPath('laravel/framework'))]);
} catch (Throwable $e) { fwrite(STDERR,$e."\n"); exit(1); }
PROBE);
    file_put_contents($directory.'/resources/views/vendor/admin/probe.blade.php','host-view');
    file_put_contents($directory.'/resources/views/vendor/admin/escaping.blade.php', '{{ $value }}');
    file_put_contents($directory.'/resources/views/escaping.blade.php', '{{ $value }}');
    file_put_contents($directory.'/resources/views/extensions.blade.php', '@fixture {{ $object }}');
    file_put_contents($directory.'/resources/views/vendor/admin/extensions.blade.php', '@fixture {{ $object }}');
    mkdir($directory.'/resources/views/vendor/admin-sibling');
    file_put_contents($directory.'/resources/views/vendor/admin-sibling/escaping.blade.php', '{{ $value }}');
    $probe = function (?string $url = null) use ($directory): string {
        $out=[]; $exit=0; exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($directory.'/probe.php').($url ? ' '.escapeshellarg($url) : '').' 2>&1', $out,$exit);
        check($exit===0, 'Native probe: '.implode("\n",$out)); return implode("\n",$out);
    };
    $uncached = $probe();
    $probe('http://admin.example.test/control-room');
    $probe('http://other.example.test/control/auth/login');
    $run(['config:cache']); $run(['route:cache']); $run(['view:cache']);
    check($probe() === $uncached, 'Cached/uncached equivalence');
    $probe('http://admin.example.test/control-room');
    $probe('http://other.example.test/control/auth/login');
    $run(['route:clear']); $run(['config:clear']); $run(['view:clear']);
    check($probe() === $uncached, 'Cleared equivalence');
    // External ancestor points inward: canonical containment must not authorize deletion.
    $alias = $directory.'-alias';
    symlink($directory.'/app', $alias);
    $target = $directory.'/app/Backoffice/Controllers/HomeController.php';
    $targetContents = file_get_contents($target);
    $overrides['directory'] = $alias.'/Backoffice';
    file_put_contents($directory.'/config/admin.php', '<?php return '.var_export($overrides, true).';');
    foreach ([false, true] as $cached) {
        if ($cached) $run(['config:cache']);
        $run(['admin:uninstall'], "yes\n");
        check(is_link($alias) && is_file($target) && file_get_contents($target) === $targetContents, 'External ancestor symlink preserved ('.($cached ? 'cached' : 'uncached').')');
        if ($cached) $run(['config:clear']);
    }
    unlink($alias);
    $overrides['directory'] = $directory.'/app/Backoffice';
    file_put_contents($directory.'/config/admin.php', $customConfig);
    $run(['admin:uninstall'], "yes\n");
    check(file_get_contents($asset) === 'custom asset' && file_get_contents($directory.'/public/business.txt') === 'business file', 'Custom and unrelated public files preserved');
    check(!is_file($directory.'/public/vendor/laravel-admin/AdminLTE/dist/js/adminlte.min.js'), 'Unchanged asset removed');
    check(!is_file($directory.'/app/Backoffice/Controllers/HomeController.php'), 'Generated controller removed');
    check(is_file($directory.'/app/Backoffice/Controllers/CustomController.php') && file_get_contents($directory.'/app/Backoffice/bootstrap.php') === '<?php // customized bootstrap', 'Custom admin files preserved');
    check(hash_file('sha256',$directory.'/config/admin.php') === $configHash && is_file($directory.'/resources/views/vendor/admin/probe.blade.php') && is_file($directory.'/lang/en/admin.php') && is_file($directory.'/database/migrations/2016_01_04_173148_create_admin_tables.php'), 'Customized config and published data preserved');
    check($business->table('business_records')->value('value') === 'preserve' && $db->table('fixture_roles')->get()->toJson() === $roles, 'Uninstall data preserved');
    copy('/application/config/admin.php', $directory.'/config/admin.php');
    $run(['admin:uninstall'], "yes\n");
    check(!is_file($directory.'/config/admin.php'), 'Unchanged published config removed');
    $outside = sys_get_temp_dir().'/laravel-admin-host-outside-'.bin2hex(random_bytes(6));
    mkdir($outside.'/Controllers', 0777, true);
    $outsideContents = str_replace('DummyNamespace', $overrides['route']['namespace'], file_get_contents('/application/src/Console/stubs/HomeController.stub'));
    file_put_contents($outside.'/Controllers/HomeController.php', $outsideContents);
    $overrides['directory'] = $outside;
    file_put_contents($directory.'/config/admin.php', '<?php return '.var_export($overrides, true).';');
    $run(['admin:uninstall'], "yes\n");
    check(file_get_contents($outside.'/Controllers/HomeController.php') === $outsideContents, 'Outside-host removal refused');
    unlink($outside.'/Controllers/HomeController.php'); rmdir($outside.'/Controllers'); rmdir($outside);
    echo json_encode(['php'=>PHP_VERSION,'framework'=>Illuminate\Foundation\Application::VERSION,'engine'=>getenv('DB_CONNECTION'),'discovery_defaults'=>true,'publish_tags'=>['config','views','lang','migrations','assets'],'fresh_repeat_partial_install'=>true,'migration_failure_exit'=>1,'publish_preservation_and_force'=>true,'selected_connection_prefix'=>'admin_conn_','https_boundaries'=>true,'escaping_isolation'=>true,'compiler_directives_echo_handlers_paths'=>true,'macro_execution'=>true,'host_pagination_preserved'=>true,'cache_equivalence'=>json_decode($uncached,true),'uninstall_boundary'=>true,'external_ancestor_symlink_cached_uncached'=>true],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
    // Do not traverse the vendor symlink during cleanup.
    unlink($directory.'/vendor');
    PackageTestHost::remove($directory);
} catch (Throwable $error) { fwrite(STDERR, $error."\n"); exit(1); }
