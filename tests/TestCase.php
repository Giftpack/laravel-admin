<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\BrowserKitTesting\TestCase as BaseTestCase;

require_once __DIR__.'/Support/PackageTestHost.php';

class TestCase extends BaseTestCase
{
    public $baseUrl = 'http://localhost';

    protected string $hostDirectory;

    public function createApplication()
    {
        PackageTestHost::assertDisposableEnvironment();
        $this->hostDirectory = PackageTestHost::directory();

        return PackageTestHost::create($this->hostDirectory);
    }

    protected function setUp(): void
    {
        parent::setUp();
        PackageTestHost::assertDisposableEnvironment();
        Schema::dropAllTables();
        Schema::defaultStringLength(191);
        $this->artisan('vendor:publish', ['--provider' => Encore\Admin\AdminServiceProvider::class]);
        $this->artisan('admin:install');
        $this->migrateTestTables();
        require admin_path('routes.php');
        require __DIR__.'/routes.php';
        fake()->seed(12345);
        require __DIR__.'/seeds/factory.php';
    }

    protected function tearDown(): void
    {
        if (isset($this->app)) {
            PackageTestHost::assertDisposableEnvironment();
            DB::disconnect();
        }
        parent::tearDown();
    }

    /** Compare the value as data, so names containing quotes cannot break a CSS selector. */
    protected function seeInputValue(string $selector, $expected): self
    {
        $elements = $this->crawler()->filter($selector);
        $this->assertCount(1, $elements);
        $this->assertSame((string) $expected, $elements->attr('value'));

        return $this;
    }

    public function migrateTestTables()
    {
        PackageTestHost::assertDisposableEnvironment();
        (new Filesystem())->requireOnce(__DIR__.'/migrations/2016_11_22_093148_create_test_tables.php');
        (new CreateTestTables())->down();
        (new CreateTestTables())->up();
    }
}
