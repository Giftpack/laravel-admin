<?php

namespace Encore\Admin\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PermissionCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:permissions {--tables=} {--connection=} {--schema=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'generate admin permission base on table name';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try {
            $connection = DB::connection($this->option('connection') ?: (config('admin.database.connection') ?: null));
            $schema = $connection->getSchemaBuilder();
            $schemaName = $this->option('schema') ?: $schema->getCurrentSchemaName();
            $prefix = $connection->getTablePrefix();
            $tables = [];
            foreach ($schema->getTables($schemaName) as $table) {
                if ($prefix !== '' && !Str::startsWith($table['name'], $prefix)) {
                    continue;
                }
                $tables[] = $prefix === '' ? $table['name'] : substr($table['name'], strlen($prefix));
            }
            $requested = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('tables'))), 'strlen'));
            if ($requested && array_diff($requested, $tables)) {
                $this->error('One or more selected tables do not exist in the selected connection/schema.');
                return self::FAILURE;
            }
            $ignored = array_map(fn ($table) => is_string($table) ? basename(str_replace('.', '/', $table)) : $table, $this->getIgnoreTables());
            $tables = array_values(array_diff($requested ?: $tables, $ignored));
            if (!$tables) {
                $this->info('No eligible tables found.');
                return self::SUCCESS;
            }
            $permissionClass = config('admin.database.permissions_model');
            $permissionModel = new $permissionClass();
            $generated = [];
            $permissionModel->getConnection()->transaction(function () use ($tables, $permissionModel, &$generated) {
                foreach ($tables as $table) {
                    foreach ($this->getPermissions() as $permission => $label) {
                        $slug = $this->generateSlug($table, $permission);
                        if ($permissionModel->newQuery()->where('slug', $slug)->exists()) {
                            $this->warn("{$slug} already exists");
                            continue;
                        }
                        $permissionModel->newQuery()->create([
                            'name' => $this->generateName($table, $label),
                            'slug' => $slug,
                            'http_method' => $this->generateHttpMethod($permission),
                            'http_path' => $this->generateHttpPath($table, $permission),
                        ]);
                        $generated[] = $slug;
                    }
                }
            });
            foreach ($generated as $slug) {
                $this->info("{$slug} generated");
            }
        } catch (\Throwable $exception) {
            $this->error('Unable to generate permissions for the selected tables.');
            return self::FAILURE;
        }
        return self::SUCCESS;
    }

    private function getIgnoreTables()
    {
        return [
            config('database.migrations.table', 'migrations'),
            config('admin.database.users_table'),
            config('admin.database.roles_table'),
            config('admin.database.permissions_table'),
            config('admin.database.menu_table'),
            config('admin.database.operation_log_table'),
            config('admin.database.user_permissions_table'),
            config('admin.database.role_users_table'),
            config('admin.database.role_permissions_table'),
            config('admin.database.role_menu_table'),
        ];
    }

    private function getPermissions()
    {
        return [
            'list'   => __('admin.list'),
            'view'   => __('admin.view'),
            'create' => __('admin.create'),
            'edit'   => __('admin.edit'),
            'delete' => __('admin.delete'),
            'export' => __('admin.export'),
            'filter' => __('admin.filter'),
        ];
    }

    private function generateHttpMethod($permission)
    {
        switch ($permission) {
            case 'create':
                $http_method = ['POST'];
                break;
            case 'edit':
                $http_method = ['PUT', 'PATCH'];
                break;
            case 'delete':
                $http_method = ['DELETE'];
                break;
            default:
                $http_method = ['GET'];
        }

        return $http_method;
    }

    private function generateHttpPath($table, $permission)
    {
        $resource = Str::kebab(Str::camel($table));
        switch ($permission) {
            case 'create':
            case 'list':
            case 'filter':
                $http_path = '/'.$resource;
                break;
            case 'edit':
            case 'delete':
            case 'view':
                $http_path = '/'.$resource.'/*';
                break;
            default:
                $http_path = '/'.$resource;
        }

        return $http_path;
    }

    private function generateSlug($table, $permission)
    {
        return Str::kebab(Str::camel($table)).'.'.$permission;
    }

    private function generateName($table, $permission_lang)
    {
        return Str::upper(Str::kebab(Str::camel($table))).$permission_lang;
    }
}
