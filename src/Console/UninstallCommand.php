<?php

namespace Encore\Admin\Console;

use Illuminate\Console\Command;

class UninstallCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'admin:uninstall';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Uninstall the admin package';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        if (!$this->confirm('Are you sure to uninstall laravel-admin?')) {
            return;
        }

        $this->removeFilesAndDirectories();

        $this->line('<info>Uninstalling laravel-admin!</info>');
    }

    /**
     * Remove files and directories.
     *
     * @return void
     */
    protected function removeFilesAndDirectories()
    {
        $files = $this->laravel['files'];
        $host = realpath(base_path());
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR);
        $removeUnchanged = function ($target, $contents) use ($files, $host, $base) {
            // Canonical containment alone permits an external symlink pointing inward.
            // Establish lexical containment before deriving or walking the relative path.
            if (!str_starts_with($target, $base.DIRECTORY_SEPARATOR)) {
                return;
            }
            $real = realpath($target);
            // Never follow symlinks or remove anything outside this host.
            if (!$real || is_link($target) || !str_starts_with($real, $host.DIRECTORY_SEPARATOR)) {
                return;
            }
            $relative = substr($target, strlen($base) + 1);
            $parent = $base;
            foreach (explode(DIRECTORY_SEPARATOR, $relative) as $segment) {
                $parent .= DIRECTORY_SEPARATOR.$segment;
                if (is_link($parent)) {
                    return;
                }
            }
            if (is_file($real) && hash_equals(hash('sha256', $contents), hash_file('sha256', $real))) {
                $files->delete($real);
            }
        };

        foreach (['HomeController', 'AuthController', 'ExampleController', 'bootstrap', 'routes'] as $stub) {
            $target = in_array($stub, ['bootstrap', 'routes'], true)
                ? admin_path($stub.'.php') : admin_path('Controllers/'.$stub.'.php');
            $contents = str_replace('DummyNamespace', config('admin.route.namespace'), $files->get(__DIR__.'/stubs/'.$stub.'.stub'));
            $removeUnchanged($target, $contents);
        }
        $removeUnchanged(config_path('admin.php'), $files->get(__DIR__.'/../../config/admin.php'));
        foreach ($files->allFiles(__DIR__.'/../../resources/assets') as $asset) {
            $removeUnchanged(public_path('vendor/laravel-admin/'.$asset->getRelativePathname()), $files->get($asset->getPathname()));
        }
        $this->line('<info>Removed unchanged generated files and assets; customized files, directories, migrations, translations, views and all database tables were preserved.</info>');
    }
}
