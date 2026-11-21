<?php

use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\Role;
use Encore\Admin\Console\AdminCommand;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Tester\CommandTester;

class ManagementCommandsTest extends TestCase
{
    private function command(string $name, array $arguments = [], array $inputs = [], bool $interactive = false): CommandTester
    {
        $tester = new CommandTester($this->app[Kernel::class]->all()[$name]);
        $tester->setInputs($inputs);
        $tester->execute($arguments, ['interactive' => $interactive]);
        return $tester;
    }

    private function adminTables(): array
    {
        return ['menu_table', 'permissions_table', 'roles_table', 'role_menu_table', 'role_permissions_table', 'users_table', 'role_users_table', 'user_permissions_table'];
    }

    private function cloneAdminDestination(): void
    {
        $config = config('database.connections.'.config('database.default'));
        $config['prefix'] = 'snapshot_';
        config(['database.connections.snapshot' => $config]);
        DB::purge('snapshot');
        $original = Schema::getFacadeRoot();
        Schema::swap(DB::connection('snapshot')->getSchemaBuilder());
        try {
            (new CreateAdminTables())->up();
        } finally {
            Schema::swap($original);
        }
    }

    private function snapshotRows($connection): array
    {
        $rows = [];
        foreach ($this->adminTables() as $key) {
            $table = config('admin.database.'.$key);
            $columns = $connection->getSchemaBuilder()->getColumnListing($table);
            $query = $connection->table($table);
            foreach ($columns as $column) {
                $query->orderBy($column);
            }
            $rows[$key] = $query->get()->map(fn ($row) => (array) $row)->all();
        }
        return $rows;
    }

    public function testReadOnlyManagementCommandsHaveCapturedUnicodeOutputAndControlledConfigFailures()
    {
        $this->assertSame(0, $this->artisan('admin'));
        $this->assertStringContainsString('admin:permissions', Artisan::output());
        $this->assertSame(4, AdminCommand::strlen('管理'));
        $this->assertSame(0, $this->artisan('admin:config'));
        $this->assertStringContainsString('have not been modified', Artisan::output());
        $customConfig = config_path('custom-admin.php');
        file_put_contents($customConfig, '<?php return '.var_export(['custom_fixture_key' => true], true).';');
        $this->assertSame(0, $this->artisan('admin:config', ['path' => $customConfig]));
        $this->assertStringContainsString('custom_fixture_key', Artisan::output());
        $this->assertSame(1, $this->artisan('admin:config', ['path' => '/does/not/exist.php']));
        $path = config_path('invalid.php');
        file_put_contents($path, '<?php return null;');
        $this->assertSame(1, $this->artisan('admin:config', ['path' => $path]));
        $this->assertStringContainsString('must return an array', Artisan::output());
        $this->be(Administrator::first(), 'admin');
        DB::table(config('admin.database.menu_table'))->insert(['parent_id' => 0, 'order' => 99, 'title' => '管理工具', 'icon' => 'fa-list', 'uri' => 'managed-tools']);
        $this->assertSame(0, $this->artisan('admin:menu'));
        $menu = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertContains('管理工具', array_column($menu, 'title'));
    }

    public function testPermissionDiscoveryUsesSelectedConnectionPrefixSchemaExclusionsAndIdempotentGrants()
    {
        $config = config('database.connections.'.config('database.default'));
        $config['prefix'] = 'domain_';
        config(['database.connections.domain' => $config]);
        DB::purge('domain');
        $domain = DB::connection('domain');
        $domain->getSchemaBuilder()->create('products', fn (Blueprint $table) => $table->increments('id'));
        $domain->getSchemaBuilder()->create('admin_users', fn (Blueprint $table) => $table->increments('id'));
        $args = ['--connection' => 'domain', '--tables' => 'products,admin_users'];
        $this->assertSame(0, $this->artisan('admin:permissions', $args));
        $permissions = DB::table(config('admin.database.permissions_table'))->where('slug', 'like', 'products.%');
        $this->assertSame(7, $permissions->count());
        $this->assertSame('/products', $permissions->where('slug', 'products.export')->value('http_path'));
        $this->assertSame(0, DB::table(config('admin.database.permissions_table'))->where('slug', 'like', 'admin-users.%')->count());
        $this->assertSame(0, $this->artisan('admin:permissions', $args));
        $this->assertSame(7, DB::table(config('admin.database.permissions_table'))->where('slug', 'like', 'products.%')->count());
        $before = DB::table(config('admin.database.permissions_table'))->count();
        $this->assertSame(1, $this->artisan('admin:permissions', ['--connection' => 'domain', '--tables' => 'products,missing']));
        $this->assertSame($before, DB::table(config('admin.database.permissions_table'))->count());
        if ($domain->getDriverName() === 'pgsql') {
            $domain->statement('CREATE SCHEMA permission_scope');
            try {
                $domain->getSchemaBuilder()->create('permission_scope.products', fn (Blueprint $table) => $table->increments('id'));
                $this->assertSame(0, $this->artisan('admin:permissions', ['--connection' => 'domain', '--schema' => 'permission_scope', '--tables' => 'products']));
            } finally {
                $domain->statement('DROP SCHEMA permission_scope CASCADE');
            }
        }
        // A later SQL failure must roll back earlier generated grants in the same command.
        $domain->getSchemaBuilder()->create('portableok', fn (Blueprint $table) => $table->increments('id'));
        $long = str_repeat('x', 49);
        $domain->getSchemaBuilder()->create($long, fn (Blueprint $table) => $table->increments('id'));
        $before = DB::table(config('admin.database.permissions_table'))->count();
        $this->assertSame(1, $this->artisan('admin:permissions', ['--connection' => 'domain', '--tables' => 'portableok,'.$long]));
        $this->assertSame($before, DB::table(config('admin.database.permissions_table'))->count());
        $this->assertStringNotContainsString('portableok.list generated', Artisan::output());
        $this->cloneAdminDestination();
        config(['admin.database.connection' => 'snapshot']);
        DB::connection('snapshot')->getSchemaBuilder()->create('widgets', fn (Blueprint $table) => $table->increments('id'));
        $this->assertSame(0, $this->artisan('admin:permissions', ['--tables' => 'widgets']));
        $this->assertSame(7, DB::connection('snapshot')->table(config('admin.database.permissions_table'))->count());
        $this->assertSame($before, DB::connection(config('database.default'))->table(config('admin.database.permissions_table'))->count());
        DB::disconnect('domain');
        DB::disconnect('snapshot');
    }

    public function testMenuGenerationHonorsPrefixDryRunExclusionsAndExistingCustomTitles()
    {
        config(['admin.route.prefix' => 'control/panel', 'admin.menu_exclude' => ['ignored']]);
        Route::get('control/panel/new-tools', fn () => 'test');
        Route::get('control/panel/ignored', fn () => 'test');
        Route::get('control/panel/new-tools/create', fn () => 'test');
        Route::get('control/panel/new-tools/{id}', fn () => 'test');
        Route::post('control/panel/write-only', fn () => 'test');
        $table = config('admin.database.menu_table');
        $before = DB::table($table)->count();
        $this->assertSame(0, $this->artisan('admin:generate-menu', ['--dry-run' => true]));
        $this->assertStringContainsString('new-tools', Artisan::output());
        $this->assertSame($before, DB::table($table)->count());
        $this->assertSame(0, $this->artisan('admin:generate-menu'));
        $this->assertSame($before + 1, DB::table($table)->count());
        DB::table($table)->where('uri', 'new-tools')->update(['title' => 'Custom title']);
        $this->assertSame(0, $this->artisan('admin:generate-menu'));
        $this->assertSame('Custom title', DB::table($table)->where('uri', 'new-tools')->value('title'));
        $this->assertSame($before + 1, DB::table($table)->count());
    }

    public function testInteractiveUserCreationSelectsOnlyRequestedRoleAndRollsBackInvalidAssignments()
    {
        Role::create(['name' => 'Limited', 'slug' => 'limited']);
        $password = 'synthetic-password-only';
        $tester = $this->command('admin:create-user', [], ['cli-user', $password, 'CLI user', '1'], true);
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $user = Administrator::where('username', 'cli-user')->firstOrFail();
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertSame(['limited'], $user->roles->pluck('slug')->all());
        $this->assertStringNotContainsString($password, $tester->getDisplay());
        $duplicate = $this->command('admin:create-user', [], ['cli-user', $password, 'Other'], true);
        $this->assertSame(2, $duplicate->getStatusCode());
        $this->assertSame(1, Administrator::where('username', 'cli-user')->count());
        $invalid = $this->command('admin:create-user', [], ['', '', ''], true);
        $this->assertSame(2, $invalid->getStatusCode());
        $this->assertSame(2, $this->command('admin:create-user')->getStatusCode());
        config(['admin.database.role_users_table' => 'missing_pivot']);
        $failed = $this->command('admin:create-user', [], ['rollback-user', $password, 'Rollback', '1'], true);
        $this->assertSame(1, $failed->getStatusCode());
        $this->assertFalse(Administrator::where('username', 'rollback-user')->exists());
        $this->assertStringNotContainsString($password, $failed->getDisplay());
    }

    public function testResetPasswordRejectsMissingMismatchEmptyAndNoninteractiveInputWithoutHanging()
    {
        $user = Administrator::first();
        $oldHash = $user->password;
        $this->assertSame(2, $this->command('admin:reset-password', [], ['missing-user'], true)->getStatusCode());
        $this->assertSame(2, $this->command('admin:reset-password', [], ['admin', 'different', 'mismatch'], true)->getStatusCode());
        $this->assertSame(2, $this->command('admin:reset-password', [], ['admin', '', ''], true)->getStatusCode());
        $this->assertSame(2, $this->command('admin:reset-password')->getStatusCode());
        $this->assertSame($oldHash, $user->fresh()->password);
        $password = 'synthetic-reset-only';
        $tester = $this->command('admin:reset-password', [], ['admin', $password, $password], true);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertTrue(Hash::check($password, $user->fresh()->password));
        $this->assertStringNotContainsString($password, $tester->getDisplay());
    }

    public function testSnapshotExportRoundTripsSelectedConnectionIdentifiersPivotsAndEscapedText()
    {
        $source = DB::connection();
        $source->table(config('admin.database.users_table'))->insert(['id' => 500, 'username' => 'snapshot-user', 'name' => "Owner's \\ name\nnext", 'password' => Hash::make('synthetic-snapshot-only'), 'created_at' => '2026-01-02 03:04:05']);
        $source->table(config('admin.database.role_users_table'))->insert(['role_id' => 1, 'user_id' => 500]);
        $source->table(config('admin.database.user_permissions_table'))->insert(['permission_id' => 1, 'user_id' => 500]);
        $expected = $this->snapshotRows($source);
        $this->assertSame(0, $this->artisan('admin:export-seed', ['classname' => 'Fixtures\\SnapshotFixtureSeeder', '--users' => true]));
        $path = database_path('seeders/Fixtures/SnapshotFixtureSeeder.php');
        $this->assertFileExists($path);
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertStringContainsString("--class='Database\\Seeders\\Fixtures\\SnapshotFixtureSeeder'", Artisan::output());
        require $path;
        $this->cloneAdminDestination();
        config(['admin.database.connection' => 'snapshot']);
        $class = 'Database\\Seeders\\Fixtures\\SnapshotFixtureSeeder';
        $this->assertSame(0, $this->artisan('db:seed', ['--class' => $class]));
        $target = DB::connection('snapshot');
        $this->assertSame($expected, $this->snapshotRows($target));
        $this->assertSame($expected, $this->snapshotRows($source));
        $nextId = $target->table(config('admin.database.users_table'))->insertGetId(['username' => 'next-user', 'name' => 'Next', 'password' => 'synthetic-hash']);
        $this->assertGreaterThan(500, $nextId);
        try {
            $this->artisan('db:seed', ['--class' => $class]);
            $this->fail('Existing destination must require explicit replacement.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('--force', $exception->getMessage());
        }
        $this->assertSame(0, $this->artisan('db:seed', ['--class' => $class, '--force' => true]));
        $this->assertSame($expected, $this->snapshotRows($target));
        DB::disconnect('snapshot');
    }

    public function testSnapshotImportRollsBackAllIncludedTablesWhenALateInsertFails()
    {
        $usersTable = config('admin.database.users_table');
        DB::table($usersTable)->insert(['username' => 'duplicate-name', 'name' => 'Administrator', 'password' => 'synthetic-sensitive-hash']);
        $this->assertSame(0, $this->artisan('admin:export-seed', ['classname' => 'RollbackSnapshotSeeder', '--users' => true]));
        require database_path('seeders/RollbackSnapshotSeeder.php');
        $sourceRows = $this->snapshotRows(DB::connection());
        $this->cloneAdminDestination();
        $target = DB::connection('snapshot');
        $target->getSchemaBuilder()->table($usersTable, fn (Blueprint $table) => $table->unique('name'));
        $target->table($usersTable)->insert(['id' => 99, 'username' => 'keep-user', 'name' => 'Keep', 'password' => 'synthetic-existing-hash']);
        $target->table(config('admin.database.roles_table'))->insert(['id' => 99, 'name' => 'Keep role', 'slug' => 'keep']);
        config(['admin.database.connection' => 'snapshot']);
        $before = $this->snapshotRows($target);
        try {
            $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\RollbackSnapshotSeeder', '--force' => true]);
            $this->fail('The target constraint must reject this snapshot.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('rolled back', $exception->getMessage());
            $this->assertStringNotContainsString('synthetic-sensitive-hash', $exception->getMessage());
        }
        $this->assertSame($before, $this->snapshotRows($target));
        $this->assertSame($sourceRows, $this->snapshotRows(DB::connection(config('database.default'))));
        DB::disconnect('snapshot');
    }

    public function testSnapshotWithoutUsersRefusesOrphanedUnexportedGrantsBeforeAnyWrite()
    {
        $this->assertSame(0, $this->artisan('admin:export-seed', ['classname' => 'NoUsersGrantSnapshotSeeder']));
        require database_path('seeders/NoUsersGrantSnapshotSeeder.php');
        $this->cloneAdminDestination();
        $target = DB::connection('snapshot');
        $target->table(config('admin.database.roles_table'))->insert(['id' => 99, 'name' => 'Keep role', 'slug' => 'keep']);
        $target->table(config('admin.database.users_table'))->insert(['id' => 77, 'username' => 'kept-user', 'name' => 'Kept user', 'password' => 'synthetic-existing-hash']);
        $target->table(config('admin.database.role_users_table'))->insert(['role_id' => 99, 'user_id' => 77]);
        config(['admin.database.connection' => 'snapshot']);
        $before = $this->snapshotRows($target);
        try {
            $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\NoUsersGrantSnapshotSeeder', '--force' => true]);
            $this->fail('Unexported user grants must not be orphaned.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('orphan unexported', $exception->getMessage());
        }
        $this->assertSame($before, $this->snapshotRows($target));
        // Matching referents permit replacement while preserving the unexported grant rows.
        $target->table(config('admin.database.role_users_table'))->update(['role_id' => 1]);
        $this->assertSame(0, $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\NoUsersGrantSnapshotSeeder', '--force' => true]));
        $this->assertSame(77, $target->table(config('admin.database.role_users_table'))->value('user_id'));
        DB::disconnect('snapshot');
    }

    public function testSnapshotWithoutUsersAndInvalidExportsPreserveFilesAndIdentifiers()
    {
        $this->assertSame(1, $this->artisan('admin:export-seed', ['classname' => '../Outside']));
        $this->assertSame(1, $this->artisan('admin:export-seed', ['classname' => 'DB']));
        $this->assertFileDoesNotExist(database_path('seeders/DB.php'));
        $this->assertSame(1, $this->artisan('admin:export-seed', ['classname' => 'RejectId', '--except-fields' => 'id']));
        $this->assertFileDoesNotExist(database_path('seeders/RejectId.php'));
        $this->assertSame(0, $this->artisan('admin:export-seed', ['classname' => 'NoUsersFixtureSeeder', '--except-fields' => 'created_at,updated_at']));
        $path = database_path('seeders/NoUsersFixtureSeeder.php');
        $before = file_get_contents($path);
        $this->assertStringNotContainsString("'users_table' =>", $before);
        $this->assertStringContainsString("'id' =>", $before);
        $this->assertSame(1, $this->artisan('admin:export-seed', ['classname' => 'NoUsersFixtureSeeder']));
        $this->assertSame($before, file_get_contents($path));
        $this->assertSame(0, $this->artisan('admin:export-seed', ['classname' => 'NoUsersFixtureSeeder', '--force' => true]));
        // Empty optional tables still have a valid column contract and compile.
        DB::table(config('admin.database.user_permissions_table'))->delete();
        $this->assertSame(0, $this->artisan('admin:export-seed', ['classname' => 'EmptyOptionalSeeder', '--users' => true]));
    }
}
