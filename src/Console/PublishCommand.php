<?php

namespace Encore\Admin\Console;

use Illuminate\Console\Command;

class PublishCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'admin:publish {--force}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "re-publish laravel-admin's assets, configuration, language and migration files. If you want overwrite the existing files, you can add the `--force` option";

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $force = $this->option('force');
        $options = ['--provider' => 'Encore\Admin\AdminServiceProvider'];
        if ($force == true) {
            $options['--force'] = true;
        }
        if ($this->call('vendor:publish', $options) !== self::SUCCESS) {
            return self::FAILURE;
        }

        return $this->call('view:clear');
    }
}
