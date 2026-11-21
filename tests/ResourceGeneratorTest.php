<?php

use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Console\ResourceGenerator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Models\GeneratorRecord;

class ResourceGeneratorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $driver = config('database.default');
        $config = config('database.connections.'.$driver);
        $config['prefix'] = 'gen_';
        config(['database.connections.generator' => $config]);
        DB::purge('generator');
        $schema = $driver === 'pgsql' ? 'public' : $config['database'];
        config(['generator.table' => $schema.'.generator_records']);
        DB::connection('generator')->getSchemaBuilder()->create(config('generator.table'), function (Blueprint $table) {
            $table->increments('record_key');
            $table->string('title')->default(DB::raw(DB::connection('generator')->getPdo()->quote("Owner's \\ note\nnext line")));
            $table->integer('quantity')->default(0);
            $table->string('empty_text')->default('');
            $table->string('zero_text')->default('0');
            $table->string("owner's_note")->nullable();
            $table->integer('computed_quantity')->storedAs('quantity + 1');
            $table->integer('expression_default')->default(DB::raw('(2 + 3)'));
            $table->boolean('enabled')->default(false);
            $table->decimal('price', 12, 2)->default('0.00');
            $table->json('payload')->nullable();
            $table->text('notes')->nullable();
            $table->date('day')->nullable();
            $table->time('clock')->nullable();
            $table->timestamp('starts_at')->useCurrent();
            $table->uuid('external_key')->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamp('changed_at')->nullable();
        });
        $this->be(Administrator::first(), 'admin');
    }

    protected function tearDown(): void
    {
        if (isset($this->app)) {
            DB::disconnect('generator');
        }
        parent::tearDown();
    }

    private function fieldDefaults(string $formCode): array
    {
        $recorder = new class {
            public array $fields = [];
            private string $name;
            public function __call($method, $args)
            {
                if ($method === 'default') {
                    $this->fields[$this->name]['default'] = $args[0];
                } else {
                    $this->name = $args[0];
                    $this->fields[$args[0]] = ['type' => $method, 'label' => $args[1]];
                }
                return $this;
            }
        };
        $form = $recorder;
        eval($formCode);
        return $recorder->fields;
    }

    public function testNativeColumnsPreserveLiteralDefaultsAndTypesOnTheModelConnection()
    {
        $generator = new ResourceGenerator(new GeneratorRecord());
        $fields = $this->fieldDefaults($generator->generateForm());
        $this->assertArrayNotHasKey('record_key', $fields);
        $this->assertArrayNotHasKey('recorded_at', $fields);
        $this->assertArrayNotHasKey('changed_at', $fields);
        $this->assertSame("Owner's \\ note\nnext line", $fields['title']['default']);
        $this->assertSame(0, $fields['quantity']['default']);
        $this->assertSame('', $fields['empty_text']['default']);
        $this->assertSame('0', $fields['zero_text']['default']);
        $this->assertArrayHasKey("owner's_note", $fields);
        $this->assertArrayNotHasKey('computed_quantity', $fields);
        $this->assertArrayNotHasKey('default', $fields['expression_default']);
        $this->assertFalse($fields['enabled']['default']);
        $this->assertSame('0.00', $fields['price']['default']);
        $this->assertNull($fields['payload']['default']);
        $this->assertSame('switch', $fields['enabled']['type']);
        $this->assertSame('number', $fields['quantity']['type']);
        $this->assertSame('decimal', $fields['price']['type']);
        $this->assertSame('date', $fields['day']['type']);
        $this->assertSame('time', $fields['clock']['type']);
        $this->assertSame('datetime', $fields['starts_at']['type']);
        $this->assertArrayNotHasKey('default', $fields['starts_at']);
        $this->assertSame('text', $fields['external_key']['type']);
        $this->assertStringContainsString("\$grid->column('record_key'", $generator->generateGrid());
        $this->assertStringContainsString("\$show->field('recorded_at'", $generator->generateShow());
    }

    public function testQualifiedSchemaAndPrefixDoNotInspectDefaultConnectionDecoy()
    {
        $default = DB::connection();
        $default->getSchemaBuilder()->create('generator_records', function (Blueprint $table) {
            $table->string('wrong_connection_only');
        });
        $code = (new ResourceGenerator(GeneratorRecord::class))->generateGrid();
        $this->assertStringContainsString("'quantity'", $code);
        $this->assertStringNotContainsString('wrong_connection_only', $code);
        if ($default->getDriverName() === 'pgsql') {
            $default->statement('CREATE SCHEMA generator_scope');
            try {
                DB::connection('generator')->getSchemaBuilder()->create('generator_scope.generator_records', function (Blueprint $table) {
                    $table->increments('record_key');
                    $table->string('other_schema_column');
                });
                config(['generator.table' => 'generator_scope.generator_records']);
                $code = (new ResourceGenerator(GeneratorRecord::class))->generateGrid();
                $this->assertStringContainsString('other_schema_column', $code);
                $this->assertStringNotContainsString('quantity', $code);
            } finally {
                $default->statement('DROP SCHEMA generator_scope CASCADE');
            }
        }
    }

    public function testGeneratedNamespacedControllerLintsLoadsAndHandlesCrud()
    {
        // Qualified schema inspection is tested separately: Laravel 12 Eloquent cannot qualify
        // find() keys correctly when a schema-qualified model table also has a connection prefix.
        config(['generator.table' => 'generator_records']);
        $name = 'Generated\\RecordsController';
        $this->assertSame(0, $this->artisan('admin:make', ['name' => $name, '--model' => GeneratorRecord::class, '--title' => "Owner's records\nNext"]));
        $path = app_path('Admin/Controllers/Generated/RecordsController.php');
        $this->assertFileExists($path);
        exec('php -l '.escapeshellarg($path), $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));
        $class = 'App\\Admin\\Controllers\\Generated\\RecordsController';
        $this->assertTrue(class_exists($class));
        Route::middleware(['web', 'admin'])->resource('admin/generated-records', $class);
        $this->visit('admin/generated-records/create')->see('Quantity')->see('Title');
        $this->call('POST', 'admin/generated-records', ['title' => 'Generated record', 'quantity' => 4, 'price' => '12.30', 'enabled' => 'on']);
        $this->assertResponseStatus(302);
        $record = GeneratorRecord::firstOrFail();
        $this->assertSame('Generated record', $record->title);
        $this->assertSame(4, $record->quantity);
        $this->visit('admin/generated-records/'.$record->getKey())->see('Generated record');
        $this->call('PUT', 'admin/generated-records/'.$record->getKey(), ['title' => 'Updated', 'quantity' => 7, 'price' => '9.20']);
        $this->assertResponseStatus(302);
        $this->assertSame('Updated', $record->fresh()->title);
        $this->call('DELETE', 'admin/generated-records/'.$record->getKey());
        $this->assertResponseStatus(200);
        $this->assertSame(0, GeneratorRecord::count());
    }

    public function testBlankAndModelCommandsValidateInputsAndNeverOverwriteExistingController()
    {
        $this->assertSame(0, $this->artisan('admin:make', ['name' => 'BlankFixtureController', '--namespace' => 'App\\Admin\\Controllers\\Custom']));
        $blank = app_path('Admin/Controllers/Custom/BlankFixtureController.php');
        $this->assertFileExists($blank);
        $this->assertStringNotContainsString('Dummy', file_get_contents($blank));
        $this->assertTrue(class_exists('App\\Admin\\Controllers\\Custom\\BlankFixtureController'));
        $before = file_get_contents($blank);
        $this->assertSame(1, $this->artisan('admin:make', ['name' => 'BlankFixtureController', '--namespace' => 'App\\Admin\\Controllers\\Custom']));
        $this->assertSame($before, file_get_contents($blank));
        $this->assertSame(0, $this->artisan('admin:controller', ['model' => GeneratorRecord::class, '--namespace' => 'App\\Admin\\Controllers\\ModelGenerated']));
        $this->assertTrue(class_exists('App\\Admin\\Controllers\\ModelGenerated\\GeneratorRecordController'));
        foreach (['MissingModelClass', stdClass::class, Illuminate\Database\Eloquent\Model::class] as $invalid) {
            $this->assertSame(1, $this->artisan('admin:controller', ['model' => $invalid]));
        }
        $this->assertSame(1, $this->artisan('admin:make', ['name' => '../InvalidController']));
        $this->assertSame(1, $this->artisan('admin:make', ['name' => 'MissingStubController', '--stub' => '/missing.stub']));
        $this->assertFileDoesNotExist(app_path('Admin/Controllers/MissingStubController.php'));
        $badStub = $this->hostDirectory.'/invalid-controller.stub';
        file_put_contents($badStub, '<?php this is invalid syntax;');
        $this->assertSame(1, $this->artisan('admin:make', ['name' => 'InvalidStubController', '--stub' => $badStub]));
        $this->assertFileDoesNotExist(app_path('Admin/Controllers/InvalidStubController.php'));
        $this->assertSame(1, $this->artisan('admin:make', ['name' => 'NoModelOutput', '--output' => true]));
        $this->assertSame(0, $this->artisan('admin:controller', ['model' => GeneratorRecord::class, '--output' => true]));
    }

    public function testExternalGeneratorNamespacesFailBeforeQualificationOrFilesystemWrites()
    {
        $before = glob(app_path('Admin/Controllers').'/*');
        $this->assertSame(1, $this->artisan('admin:make', ['name' => 'ExternalNamespaceController', '--namespace' => 'Vendor\\Admin']));
        $this->assertFileDoesNotExist(app_path('Vendor/Admin/ExternalNamespaceController.php'));
        $this->assertFileDoesNotExist(app_path('Admin/Controllers/ExternalNamespaceController.php'));
        $this->assertSame($before, glob(app_path('Admin/Controllers').'/*'));
        $previous = config('admin.route.namespace');
        try {
            config(['admin.route.namespace' => 'Vendor\\Admin']);
            $this->assertSame(1, $this->artisan('admin:make', ['name' => 'InvalidConfiguredNamespaceController']));
            $this->assertSame($before, glob(app_path('Admin/Controllers').'/*'));
        } finally {
            config(['admin.route.namespace' => $previous]);
        }
    }

    public function testCompilerNameConflictsAndInvalidCustomStubsNeverWriteControllers()
    {
        // These are syntactically parseable, but the PHP compiler rejects class/import conflicts.
        foreach (['GeneratorRecord', 'Form', 'Grid', 'Show', 'AdminController'] as $name) {
            $this->assertSame(1, $this->artisan('admin:make', ['name' => $name, '--model' => GeneratorRecord::class]));
            $this->assertFileDoesNotExist(app_path('Admin/Controllers/'.$name.'.php'));
        }
        $stub = $this->hostDirectory.'/compile-invalid-controller.stub';
        file_put_contents($stub, '<?php namespace DummyNamespace; class DummyClass { public function same() {} public function same() {} }');
        $this->assertSame(1, $this->artisan('admin:make', ['name' => 'CompileInvalidController', '--stub' => $stub]));
        $this->assertFileDoesNotExist(app_path('Admin/Controllers/CompileInvalidController.php'));
    }

    public function testMissingTablesAndInvalidModelValuesFailWithoutWritingFiles()
    {
        config(['generator.table' => 'missing_generator_table']);
        $this->assertSame(1, $this->artisan('admin:make', ['name' => 'MissingTableController', '--model' => GeneratorRecord::class]));
        $this->assertFileDoesNotExist(app_path('Admin/Controllers/MissingTableController.php'));
        foreach ([null, [], new stdClass()] as $invalid) {
            try {
                new ResourceGenerator($invalid);
                $this->fail('Expected invalid model rejection.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('Eloquent', $exception->getMessage());
            }
        }
    }
}
