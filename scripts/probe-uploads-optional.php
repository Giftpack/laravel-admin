<?php
/** A real no-dev Composer install, isolated from both existing vendor graphs. */
try {
    if (($argv[1] ?? '') === '--probe') {
        require $argv[2].'/vendor/autoload.php';
        require '/application/tests/Support/PackageTestHost.php';
        PackageTestHost::assertDisposableEnvironment();
        if (class_exists(Intervention\Image\ImageManager::class) || Composer\InstalledVersions::isInstalled('intervention/image')) throw new RuntimeException('Intervention unexpectedly installed.');
        $app=PackageTestHost::create(PackageTestHost::directory());
        $path=tempnam(storage_path(),'optional');$gd=imagecreatetruecolor(3,2);imagepng($gd,$path);imagedestroy($gd);
        $upload=new Illuminate\Http\UploadedFile($path,'plain.png','image/png',null,true);
        $form=new Encore\Admin\Form(new Encore\Admin\Auth\Database\Administrator());
        $field=$form->image('avatar');
        if (!$field->getValidator(['avatar'=>$upload])->passes()) throw new RuntimeException('Native image validation failed without Intervention.');
        $stored=$field->prepare($upload);
        if (!Illuminate\Support\Facades\Storage::disk(config('admin.upload.disk'))->exists($stored)) throw new RuntimeException('Plain upload failed without Intervention.');
        foreach (['rotate','thumbnail'] as $method) {
            try { $method==='rotate' ? $field->rotate(90) : $field->thumbnail('small',8,8); throw new LogicException('Missing dependency unexpectedly accepted.'); }
            catch (RuntimeException $e) { if (!str_contains($e->getMessage(),'intervention/image ^3.11')) throw $e; }
        }
        echo json_encode(['php'=>PHP_VERSION,'laravel'=>Illuminate\Foundation\Application::VERSION,'intervention_installed'=>false,'plain_image_validation'=>true,'plain_image_upload'=>true,'transform_error'=>true,'thumbnail_error'=>true,'gd'=>extension_loaded('gd'),'exif'=>extension_loaded('exif')],JSON_PRETTY_PRINT).PHP_EOL;
        exit;
    }
    require '/application/tests/Support/PackageTestHost.php';
    PackageTestHost::assertDisposableEnvironment();
    $dir=sys_get_temp_dir().'/admin-no-intervention-'.bin2hex(random_bytes(6));mkdir($dir);mkdir($dir.'/vendor');
    register_shutdown_function(function () use ($dir) {
        $remove=function ($path) use (&$remove) {
            if (is_link($path) || is_file($path)) { unlink($path); return; }
            foreach (scandir($path) as $name) if ($name!=='.'&&$name!=='..') $remove($path.'/'.$name);
            rmdir($path);
        };
        if (is_dir($dir)) $remove($dir);
    });
    foreach (['composer.json','composer.lock'] as $name) copy('/host/'.$name,$dir.'/'.$name);
    // Seed the disposable install from immutable installed packages; Composer still removes
    // dev packages and regenerates its real installed metadata and autoload graph.
    $metadata=json_decode(file_get_contents('/host/vendor/composer/installed.json'),true);
    foreach ($metadata['packages'] as $package) {
        $path=$dir.'/vendor/'.$package['name'];if (!is_dir(dirname($path))) mkdir(dirname($path),0777,true);
        symlink('/host/vendor/'.$package['name'],$path);
    }
    $copy='cp -R '.escapeshellarg('/host/vendor/composer').' '.escapeshellarg($dir.'/vendor/composer');
    passthru($copy,$code);if ($code!==0) throw new RuntimeException('Could not seed Composer metadata.');
    copy('/host/vendor/autoload.php',$dir.'/vendor/autoload.php');
    $command='cd '.escapeshellarg($dir).' && composer install --no-dev --no-scripts --no-progress --no-interaction';
    passthru($command,$code);if ($code!==0) throw new RuntimeException('No-dev Composer install failed.');
    if (is_dir($dir.'/vendor/intervention/image')) throw new RuntimeException('Composer retained optional Intervention.');
    passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' --probe '.escapeshellarg($dir),$code);
    if ($code!==0) throw new RuntimeException('No-dependency runtime probe failed.');
} catch (Throwable $e) { fwrite(STDERR,get_class($e).': '.$e->getMessage().PHP_EOL);exit(1); }
