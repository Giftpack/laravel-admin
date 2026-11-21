<?php

namespace Encore\Admin\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

class ExportSeedCommand extends Command
{
    protected $signature = 'admin:export-seed {classname=AdminTablesSeeder}
        {--users : Include admin users and their grants}
        {--except-fields= : Exclude non-identifier fields}
        {--force : Replace an existing generated seeder file}';
    protected $description = 'Export an ID-preserving admin data snapshot as a Laravel seeder';

    public function handle()
    {
        try {
            $name = trim(str_replace('/', '\\', (string) $this->argument('classname')), '\\');
            $name = preg_replace('/^Database\\\\Seeders\\\\/', '', $name);
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $name)) {
                throw new \InvalidArgumentException('The seeder class must be a valid name inside Database\\Seeders.');
            }
            $parts = explode('\\', $name);
            $class = array_pop($parts);
            $namespace = 'Database\\Seeders'.($parts ? '\\'.implode('\\', $parts) : '');
            $path = $this->laravel->databasePath().'/seeders/'.str_replace('\\', '/', $name).'.php';
            if (is_file($path) && !$this->option('force')) {
                throw new \InvalidArgumentException('The seeder already exists; use --force to replace the file.');
            }
            $except = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('except-fields'))), 'strlen'));
            foreach ($except as $field) {
                if ($field === 'id' || preg_match('/_id$/D', $field)) {
                    throw new \InvalidArgumentException('Identifier fields cannot be excluded from an admin snapshot.');
                }
            }
            $keys = ['menu_table', 'permissions_table', 'roles_table', 'role_menu_table', 'role_permissions_table'];
            if ($this->option('users')) {
                $keys = array_merge($keys, ['users_table', 'role_users_table', 'user_permissions_table']);
            }
            $connection = DB::connection(config('admin.database.connection') ?: null);
            $snapshot = $connection->transaction(function () use ($connection, $keys, $except) {
                $snapshot = [];
                foreach ($keys as $key) {
                    $table = config('admin.database.'.$key);
                    if (!is_string($table) || $table === '') {
                        throw new \InvalidArgumentException('The configured admin table is invalid.');
                    }
                    $builder = $connection->getSchemaBuilder();
                    $primary = [];
                    foreach ($builder->getIndexes($table) as $index) {
                        if ($index['primary']) {
                            $primary = array_merge($primary, $index['columns']);
                        }
                    }
                    if (array_intersect($primary, $except)) {
                        throw new \InvalidArgumentException('Primary identifier fields cannot be excluded from an admin snapshot.');
                    }
                    $columns = array_values(array_diff($builder->getColumnListing($table), $except));
                    if (!$columns) {
                        throw new \InvalidArgumentException('No columns remain in an exported admin table.');
                    }
                    $snapshot[$key] = [
                        'columns' => $columns,
                        'rows' => $connection->table($table)->orderBy($columns[0])->get($columns)->map(fn ($row) => (array) $row)->all(),
                    ];
                }
                return $snapshot;
            });
            $source = strtr($this->getStub('AdminTablesSeeder'), [
                'DummyNamespace' => $namespace,
                'DummyClass' => $class,
                'DummySnapshot' => var_export($snapshot, true),
            ]);
            $lint = new Process([PHP_BINARY, '-l'], null, null, $source, 10);
            if ($lint->run() !== 0) {
                throw new \InvalidArgumentException('The generated seeder did not compile.');
            }
            $this->writeSnapshot($path, $source);
        } catch (\Throwable $exception) {
            // Never print SQL bindings or snapshot contents, particularly with --users.
            $this->error($exception instanceof \InvalidArgumentException ? $exception->getMessage() : 'Unable to export the admin snapshot.');
            return self::FAILURE;
        }
        $this->info('Admin snapshot seeder created. User snapshots contain password hashes; protect the generated file.');
        $this->line("Use: php artisan db:seed --class='".$namespace.'\\'.$class."'");
        $this->comment('Existing destination data is refused unless db:seed --force explicitly authorizes replacement.');
        return self::SUCCESS;
    }

    protected function writeSnapshot(string $path, string $source): void
    {
        $files = $this->laravel['files'];
        $root = $this->laravel->databasePath().'/seeders';
        $files->ensureDirectoryExists($root);
        $root = realpath($root);
        $directory = $root;
        $relative = substr(dirname($path), strlen($this->laravel->databasePath().'/seeders'));
        foreach (array_filter(explode('/', $relative), 'strlen') as $part) {
            $directory .= DIRECTORY_SEPARATOR.$part;
            if (is_link($directory)) {
                throw new \InvalidArgumentException('The seeder destination cannot traverse a symbolic link.');
            }
            $files->ensureDirectoryExists($directory);
        }
        $directory = realpath($directory);
        if (is_link($path) || ($directory !== $root && !str_starts_with($directory, $root.DIRECTORY_SEPARATOR))) {
            throw new \InvalidArgumentException('The seeder destination must remain inside database/seeders.');
        }
        $temporary = tempnam($directory, '.admin-seed-');
        if ($temporary === false) {
            throw new \RuntimeException('The seeder file could not be prepared.');
        }
        try {
            // tempnam creates mode 0600: snapshots may contain user hashes or tokens.
            if (file_put_contents($temporary, $source, LOCK_EX) !== strlen($source) || !rename($temporary, $path)) {
                throw new \RuntimeException('The seeder file could not be written.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    protected function getStub($name)
    {
        return $this->laravel['files']->get(__DIR__.'/stubs/'.$name.'.stub');
    }
}
