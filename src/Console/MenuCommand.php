<?php

namespace Encore\Admin\Console;

use Encore\Admin\Facades\Admin;
use Illuminate\Console\Command;

class MenuCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'admin:menu';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show the admin menu';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $menu = Admin::menu();

        $this->line(json_encode($menu, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
