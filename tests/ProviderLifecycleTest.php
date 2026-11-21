<?php

use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\Role;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Tester\CommandTester;

class ProviderLifecycleTest extends TestCase
{
    public function testRepeatedInstallationPreservesPasswordAndPartialSeedData(): void
    {
        $password = password_hash('host-password', PASSWORD_BCRYPT);
        Administrator::query()->update(['password' => $password]);
        $this->assertSame(0, $this->artisan('admin:install'));
        $this->assertSame($password, Administrator::first()->password);
        Administrator::query()->delete();
        $roles = Role::all()->toArray();
        $this->assertSame(0, $this->artisan('admin:install'));
        $this->assertSame(0, Administrator::count());
        $this->assertSame($roles, Role::all()->toArray());
    }

    public function testUninstallRefusesExternalAncestorSymlinkPointingIntoHost(): void
    {
        $directory = config('admin.directory');
        $target = admin_path('Controllers/HomeController.php');
        $contents = file_get_contents($target);
        // A lexical prefix lookalike with a nested path, not a symlink at the target.
        $alias = $this->hostDirectory.'-alias-'.bin2hex(random_bytes(4));
        symlink(dirname($directory), $alias);
        config(['admin.directory' => $alias.'/'.basename($directory)]);
        try {
            $tester = new CommandTester($this->app[Kernel::class]->all()['admin:uninstall']);
            $tester->setInputs(['yes']);
            $tester->execute([], ['interactive' => true]);
            $this->assertSame(0, $tester->getStatusCode());
            $this->assertTrue(is_link($alias));
            $this->assertFileExists($target);
            $this->assertSame($contents, file_get_contents($target));
        } finally {
            config(['admin.directory' => $directory]);
            unlink($alias);
        }
    }

    public function testUninstallPreservesCustomFilesAndDataAndRejectsSymlinkTargets(): void
    {
        $generated = [];
        foreach (['Controllers/AuthController.php', 'Controllers/ExampleController.php', 'routes.php'] as $file) {
            $generated[$file] = file_get_contents(admin_path($file));
        }
        $custom = admin_path('Controllers/HostOwned.php');
        file_put_contents($custom, '<?php // business controller');
        $bootstrap = admin_path('bootstrap.php');
        $original = file_get_contents($bootstrap);
        file_put_contents($bootstrap, '<?php // custom');
        $home = admin_path('Controllers/HomeController.php');
        $saved = file_get_contents($home);
        $outside = $this->hostDirectory.'/outside.php';
        file_put_contents($outside, $saved);
        unlink($home);
        symlink($outside, $home);
        $tester = new CommandTester($this->app[Kernel::class]->all()['admin:uninstall']);
        $tester->setInputs(['yes']);
        $tester->execute([], ['interactive' => true]);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertFileExists($custom);
        $this->assertSame('<?php // custom', file_get_contents($bootstrap));
        $this->assertFileExists($outside);
        $this->assertTrue(is_link($home));
        $this->assertSame(1, Administrator::count());
        $this->assertSame(1, DB::table(config('admin.database.roles_table'))->count());
        // Shared run-scoped host is restored for subsequent retained scenarios.
        unlink($home); file_put_contents($home, $saved);
        file_put_contents($bootstrap, $original);
        unlink($custom); unlink($outside);
        foreach ($generated as $file => $contents) file_put_contents(admin_path($file), $contents);
    }
}
