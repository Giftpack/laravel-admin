<?php

use Encore\Admin\Admin;
use Encore\Admin\Extension;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\Menu;
use Encore\Admin\Auth\Database\Permission;
use Illuminate\Support\Facades\Artisan;

class ExtensionContractField extends Form\Field\Text
{
    protected static $css = ['/extension-field.css'];
    protected static $js = ['/extension-field.js'];
    public function prepare($value) { return strtoupper(trim($value)); }
}
class ExtensionContractExporter extends Grid\Exporters\CsvExporter {}
class ExtensionContractFixture extends Extension
{
    protected $name = 'contract';
    public $css = ['/extension.css'];
    public $js = ['/extension.js'];
    public $menu = ['title'=>'Contract','path'=>'contract','icon'=>'fa-list','children'=>[['title'=>'Child','path'=>'contract/child','icon'=>'fa-list']]];
    public $permission = ['name'=>'Contract','slug'=>'contract','path'=>['contract','contract/*'],'method'=>['GET']];
    public static function boot()
    {
        if (!parent::boot()) return false;
        Form::extend('contract', ExtensionContractField::class);
        Form::alias('contract','contractAlias');
        Grid\Column::extend('contract', function ($value) { return 'Contract: '.$value; });
        Grid\Exporter::extend('contract', ExtensionContractExporter::class);
        return true;
    }
}

class ExtensionToolsTest extends TestCase
{
    private array $registrySnapshot;
    protected function setUp(): void
    {
        parent::setUp();
        $this->registrySnapshot=[Form::$availableFields,Form::$fieldAlias,Grid\Column::$displayers,(new ReflectionProperty(Grid\Exporter::class,'drivers'))->getValue(),Admin::$extensions,Admin::$css,Admin::$js,Admin::$script];
    }
    protected function tearDown(): void
    {
        [Form::$availableFields,Form::$fieldAlias,Grid\Column::$displayers,$drivers,Admin::$extensions,Admin::$css,Admin::$js,Admin::$script]=$this->registrySnapshot;
        (new ReflectionProperty(Grid\Exporter::class,'drivers'))->setValue(null,$drivers);
        parent::tearDown();
    }

    public function testExtensionLifecycleImportsAndRealCustomFieldPersistence(): void
    {
        $fields=Form::$availableFields; $aliases=Form::$fieldAlias; $extensions=Admin::$extensions;
        try {
            config(['admin.extensions.contract.enable'=>false]);
            $this->assertFalse(ExtensionContractFixture::boot());
            $this->assertSame(false,ExtensionContractFixture::config('enable'));
            $this->assertFalse(Form::findFieldClass('contract'));
            $this->assertSame(1,$this->artisan('admin:import',['extension'=>'contract']));
            config(['admin.extensions.contract.enable'=>true]);
            $this->assertTrue(ExtensionContractFixture::boot());
            $this->assertTrue(ExtensionContractFixture::boot());
            $this->assertSame(0,$this->artisan('admin:import',['extension'=>'contract']));
            $permission=Permission::where('slug','contract')->firstOrFail();
            Administrator::first()->permissions()->attach($permission);
            $before=Menu::where('uri','like','contract%')->get()->toArray();
            $this->assertSame(0,$this->artisan('admin:import',['extension'=>'contract']));
            $this->assertSame($before,Menu::where('uri','like','contract%')->get()->toArray());
            $this->assertSame(1,Administrator::first()->permissions()->where('slug','contract')->count());
            $this->assertSame(1,Permission::where('slug','contract')->count());
            $this->assertSame(['GET'],$permission->http_method);
            config(['admin.extensions.contract.enable'=>false]);
            $this->assertSame(1,$this->artisan('admin:import',['extension'=>'contract']));
            $this->assertSame($before,Menu::where('uri','like','contract%')->get()->toArray());
            // Disablement gates future boot/import/routes; it never revokes existing grants or undoes global registrations.
            $this->assertSame(ExtensionContractField::class,Form::findFieldClass('contractAlias'));
            view()->share('errors',new Illuminate\Support\ViewErrorBag());
            $form=new Form(new Administrator()); $field=$form->contractAlias('name')->default('Default')->rules('required');
            $form->text('username'); $form->password('password');
            $this->assertSame('Default',$field->value()); $field->fill(['name'=>'Filled']);
            $this->assertStringContainsString('value="Filled"',(string)$field->render());
            $this->assertFalse($field->getValidator(['name'=>''])->passes());
            $this->assertContains('/extension-field.js',Form::collectFieldAssets()['js']);
            request()->replace(['name'=>'  custom consumer  ','username'=>'extension-user','password'=>'fixture']);
            $form->store();
            $this->assertSame('CUSTOM CONSUMER',Administrator::where('username','extension-user')->value('name'));
            $grid=new Grid(new Administrator()); $grid->column('name')->contract();
            $grid->disableActions();
            $this->assertStringContainsString('Contract: CUSTOM CONSUMER',$grid->render());
            $this->assertInstanceOf(ExtensionContractExporter::class,(new Grid\Exporter(new Grid(new Administrator())))->resolve('contract'));
            Form::forget('contract');
            $this->assertFalse(Form::findFieldClass('contractAlias'));
            $this->assertNotContains('/extension-field.js',Form::collectFieldAssets()['js']);
        } finally {Form::$availableFields=$fields;Form::$fieldAlias=$aliases;Admin::$extensions=$extensions;}
    }

    public function testImportValidationRollsBackMenusAndPreservesConflictingGrants(): void
    {
        $extensions=Admin::$extensions;
        try {
            config(['admin.extensions.contract.enable'=>true]); ExtensionContractFixture::boot();
            Permission::create(['name'=>'Host owned','slug'=>'contract','http_path'=>'/host','http_method'=>['POST']]);
            $before=Menu::count();
            $this->assertSame(1,$this->artisan('admin:import',['extension'=>'contract']));
            $this->assertSame($before,Menu::count());
            $this->assertSame('/host',Permission::where('slug','contract')->value('http_path'));
            $this->assertSame(1,$this->artisan('admin:import',['extension'=>'unknown']));
            $tester = new Symfony\Component\Console\Tester\CommandTester($this->app[Illuminate\Contracts\Console\Kernel::class]->all()['admin:import']);
            $this->assertSame(1,$tester->execute([],['interactive'=>false]));
        } finally {Admin::$extensions=$extensions;Form::forget('contract');}
    }

    public function testExtensionImportsUseTheConfiguredModelsConnectionWithAnUnusableDefault(): void
    {
        $default=config('database.default');$extensions=Admin::$extensions;$fields=Form::$availableFields;$aliases=Form::$fieldAlias;
        config(['database.connections.extension_selected'=>config('database.connections.'.$default),'database.connections.unusable'=>['driver'=>'unsupported'],'admin.database.connection'=>'extension_selected','database.default'=>'unusable','admin.extensions.contract.enable'=>true]);
        try {
            ExtensionContractFixture::boot();
            $this->assertSame(0,$this->artisan('admin:import',['extension'=>'contract']));
            $this->assertSame(0,$this->artisan('admin:import',['extension'=>'contract']));
            $this->assertSame(2,Menu::where('uri','like','contract%')->count());
            $this->assertSame(1,Permission::where('slug','contract')->count());
        } finally {
            config(['database.default'=>$default,'admin.database.connection'=>null]);
            Illuminate\Support\Facades\DB::purge('extension_selected');Admin::$extensions=$extensions;Form::$availableFields=$fields;Form::$fieldAlias=$aliases;
        }
    }

    public function testGeneratorsRejectInvalidInputAndPreserveExistingOutputs(): void
    {
        foreach (['admin:form','admin:action'] as $command) {
            foreach (['../Traversal','Bad-Name','class'] as $name) $this->assertSame(1,$this->artisan($command,['name'=>$name]));
            $this->assertSame(1,$this->artisan($command,['name'=>'Valid','--namespace'=>'Bad;Namespace']));
            $name='Generated'.str_replace(':','',$command);
            $args=['name'=>$name,'--namespace'=>'App\\Admin\\Generated'];
            $args[$command==='admin:form'?'--title':'--name']="User's \$value <script>";
            $this->assertSame(0,$this->artisan($command,$args));
            $path=app_path('Admin/Generated/'.$name.'.php');$source=file_get_contents($path);
            token_get_all($source,TOKEN_PARSE);
            $this->assertSame(1,$this->artisan($command,$args));
            $this->assertSame($source,file_get_contents($path));
        }
        $this->assertSame(1,$this->artisan('admin:action',['name'=>'Ambiguous','--grid-row'=>true,'--grid-batch'=>true]));
        $this->assertSame(1,$this->artisan('admin:action',['name'=>'Ambiguous','--form'=>true,'--dialog'=>true]));
        config(['admin.extension_dir'=>storage_path('extensions')]);
        foreach (['../escape','UPPER/name','vendor/../escape','vendor/name;'] as $name) $this->assertSame(1,$this->artisan('admin:extend',['extension'=>$name]));
        $this->assertSame(1,$this->artisan('admin:extend',['extension'=>'vendor/valid','--namespace'=>'Bad;Namespace']));
        $this->assertSame(0,$this->artisan('admin:extend',['extension'=>'vendor/valid','--namespace'=>'Vendor\\Nested\\Valid']));
        $path=storage_path('extensions/vendor/valid/composer.json');$source=file_get_contents($path);
        $metadata=json_decode($source,true,512,JSON_THROW_ON_ERROR);
        $this->assertSame('~8.5.13',$metadata['require']['php']);
        $this->assertSame('^2.0',$metadata['require']['encore/laravel-admin']);
        $this->assertSame(1,$this->artisan('admin:extend',['extension'=>'vendor/valid','--namespace'=>'Vendor\\Nested\\Valid']));
        $this->assertSame($source,file_get_contents($path));
    }

    public function testGeneratorsCompileBeforeWritingAndRejectReservedTerminalNames(): void
    {
        foreach (['String','sTrInG','Int','iNt','Self','sElF','Parent','Static','Object','Bool','Float','Void','Never','Mixed','Iterable'] as $name) {
            foreach (['admin:form','admin:action'] as $command) {
                $this->assertSame(1,$this->artisan($command,['name'=>$name,'--namespace'=>'App\\CompilerRejected']));
                $this->assertFileDoesNotExist(app_path('CompilerRejected/'.$name.'.php'));
                $this->assertDirectoryDoesNotExist(app_path('CompilerRejected'));
            }
            $package='vendor/reserved-'.strtolower($name);
            config(['admin.extension_dir'=>storage_path('compiler-extensions')]);
            $this->assertSame(1,$this->artisan('admin:extend',['extension'=>$package,'--namespace'=>'Vendor\\'.$name]));
            $this->assertDirectoryDoesNotExist(storage_path('compiler-extensions/'.$package));
            // The same compiler check must apply to the class derived from a valid package name.
            $package='derived/'.strtolower($name);
            $this->assertSame(1,$this->artisan('admin:extend',['extension'=>$package]));
            $this->assertDirectoryDoesNotExist(storage_path('compiler-extensions/'.$package));
        }
        foreach (['admin:form'=>'StringValueForm','admin:action'=>'IntValueAction'] as $command=>$name) {
            $this->assertSame(0,$this->artisan($command,['name'=>$name,'--namespace'=>'App\\CompilerValid']));
            $path=app_path('CompilerValid/'.$name.'.php');
            $process=new Symfony\Component\Process\Process([PHP_BINARY,'-l',$path]);$process->setTimeout(10);$process->run();
            $this->assertTrue($process->isSuccessful(),$process->getErrorOutput().$process->getOutput());
            $this->assertTrue(class_exists('App\\CompilerValid\\'.$name));
        }
        $this->assertSame(0,$this->artisan('admin:extend',['extension'=>'vendor/string-value']));
        $path=storage_path('compiler-extensions/vendor/string-value/src/StringValue.php');
        $process=new Symfony\Component\Process\Process([PHP_BINARY,'-l',$path]);$process->setTimeout(10);$process->run();
        $this->assertTrue($process->isSuccessful(),$process->getErrorOutput().$process->getOutput());
        require_once $path;
        $this->assertTrue(class_exists('Vendor\\StringValue\\StringValue'));
    }

    public function testAbsentOptionalDependenciesHaveControlledErrorsAndDoNotBreakBoot(): void
    {
        if (!class_exists(Mews\Captcha\Captcha::class)) {
            try {(new Form(new Administrator()))->captcha('captcha');$this->fail('Missing captcha accepted');}
            catch (Exception $e) {$this->assertStringContainsString('mews/captcha',$e->getMessage());}
        }
        if (!class_exists(MatthiasMullie\Minify\Minify::class)) {
            $this->assertSame(1,$this->artisan('admin:minify'));
            $this->assertStringContainsString('matthiasmullie/minify',Artisan::output());
        }
        $this->assertSame(0,$this->artisan('admin:minify',['--clear'=>true]));
        $this->assertInstanceOf(Encore\Admin\AdminServiceProvider::class,$this->app->getProvider(Encore\Admin\AdminServiceProvider::class));
    }
}
