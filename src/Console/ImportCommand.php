<?php

namespace Encore\Admin\Console;

use Encore\Admin\Admin;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

class ImportCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'admin:import {extension?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import a Laravel-admin extension';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $extension = $this->argument('extension');

        if (empty($extension)) {
            if (empty(Admin::$extensions) || !$this->input->isInteractive()) {
                $this->error('Specify a registered extension to import.');
                return self::FAILURE;
            }
            $extension = $this->choice('Please choose an extension to import', array_keys(Admin::$extensions));
        }
        if (!Arr::has(Admin::$extensions, $extension)) {
            $this->error('Unknown extension.');
            return self::FAILURE;
        }

        $className = Arr::get(Admin::$extensions, $extension);

        if (!class_exists($className) || !method_exists($className, 'import')) {
            $this->error("Invalid Extension [$className]");

            return self::FAILURE;
        }

        try {
            call_user_func([$className, 'import'], $this);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $this->info("Extension [$className] imported");
        return self::SUCCESS;
    }
}
