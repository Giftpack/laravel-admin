<?php

namespace Encore\Admin\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class DependencyHostPreparationTest extends TestCase
{
    private string $checkout;

    protected function setUp(): void
    {
        $this->checkout = sys_get_temp_dir().'/admin-dependency-checkout-'.bin2hex(random_bytes(8));
        mkdir($this->checkout.'/scripts', 0777, true);
        mkdir($this->checkout.'/config', 0777, true);
        copy(dirname(__DIR__).'/scripts/prepare-dependency-host.php', $this->checkout.'/scripts/prepare-dependency-host.php');
        copy(dirname(__DIR__).'/config/admin.php', $this->checkout.'/config/admin.php');
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->checkout, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->checkout);
    }

    public function testEveryCiHostCanBeMaterializedFromOnlyVersionedInputs(): void
    {
        self::assertDirectoryDoesNotExist($this->checkout.'/.scratch');
        self::assertDirectoryDoesNotExist($this->checkout.'/vendor');
        foreach (['current', 'lowest', 'optional', 'optional-lowest', 'excel', 'excel-lowest'] as $lane) {
            $source = dirname(__DIR__).'/tests/Fixtures/dependencies/host-'.$lane;
            $target = $this->checkout.'/tests/Fixtures/dependencies/host-'.$lane;
            mkdir($target, 0777, true);
            foreach (['composer.json', 'composer.lock'] as $name) {
                copy($source.'/'.$name, $target.'/'.$name);
            }
            $process = new Process([PHP_BINARY, $this->checkout.'/scripts/prepare-dependency-host.php', $lane], $this->checkout);
            $process->mustRun();
            $host = $this->checkout.'/.scratch/tests/dependencies/host-'.$lane;
            self::assertSame(file_get_contents($source.'/composer.json'), file_get_contents($host.'/composer.json'));
            self::assertSame(file_get_contents($source.'/composer.lock'), file_get_contents($host.'/composer.lock'));
            foreach (['bootstrap/app.php', 'config/app.php', 'config/admin.php', 'config/cache.php', 'config/session.php', 'routes/web.php'] as $name) {
                self::assertFileExists($host.'/'.$name);
                (new Process([PHP_BINARY, '-l', $host.'/'.$name]))->mustRun();
            }
            self::assertFileExists($host.'/resources/views/dependency-probe.blade.php');
        }
    }

    public function testMissingFrozenInputFailsBeforeCreatingAnApplication(): void
    {
        $process = new Process([PHP_BINARY, $this->checkout.'/scripts/prepare-dependency-host.php', 'current'], $this->checkout);
        $process->run();
        self::assertFalse($process->isSuccessful());
        self::assertStringContainsString('Missing versioned dependency fixture', $process->getOutput().$process->getErrorOutput());
        self::assertDirectoryDoesNotExist($this->checkout.'/.scratch');
    }
}
