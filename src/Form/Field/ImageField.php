<?php

namespace Encore\Admin\Form\Field;

use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

trait ImageField
{
    /**
     * Intervention calls.
     *
     * @var array
     */
    protected $interventionCalls = [];

    /**
     * Thumbnail settings.
     *
     * @var array
     */
    protected $thumbnails = [];

    /**
     * Default directory for file to upload.
     *
     * @return mixed
     */
    public function defaultDirectory()
    {
        return config('admin.upload.directory.image');
    }

    /**
     * Execute Intervention calls.
     *
     * @param string $target
     *
     * @return mixed
     */
    public function callInterventionMethods($target)
    {
        if (!empty($this->interventionCalls)) {
            $this->requireImageManager();
            $manager = new ImageManager(new Driver());
            $image = $manager->read($target);
            foreach ($this->interventionCalls as $call) {
                $method = $call['method'];
                $arguments = $call['arguments'];
                // v2's explicit axis remains supported; v3 uses flip()/flop().
                if ($method === 'flip' && $arguments) {
                    $axis = array_shift($arguments);
                    if (!in_array($axis, ['h', 'v'], true)) {
                        throw new \InvalidArgumentException('Image flip axis must be h or v.');
                    }
                    $method = $axis === 'h' ? 'flop' : 'flip';
                }
                $result = $image->$method(...$arguments);
                if (!$result instanceof \Intervention\Image\Interfaces\ImageInterface) {
                    throw new \InvalidArgumentException('Image field calls must return an image; encoding follows the stored extension.');
                }
                $image = $result;
            }
            file_put_contents($target, (string) $image->encodeByExtension(pathinfo($this->name, PATHINFO_EXTENSION)));

        }

        return $target;
    }

    protected function requireImageManager(): void
    {
        if (!class_exists(ImageManager::class)) {
            throw new \RuntimeException('Image transformations and thumbnails require optional intervention/image ^3.11.');
        }
        if (!extension_loaded('gd')) {
            throw new \RuntimeException('Image transformations and thumbnails require the PHP GD extension.');
        }
    }

    /**
     * Call intervention methods.
     *
     * @param string $method
     * @param array  $arguments
     *
     * @throws \Exception
     *
     * @return $this
     */
    public function __call($method, $arguments)
    {
        if (static::hasMacro($method)) {
            return $this;
        }

        $this->requireImageManager();

        $this->interventionCalls[] = [
            'method'    => $method,
            'arguments' => $arguments,
        ];

        return $this;
    }

    /**
     * Render a image form field.
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function render()
    {
        $this->options(['allowedFileTypes' => ['image'], 'msgPlaceholder' => trans('admin.choose_image')]);

        return parent::render();
    }

    /**
     * @param string|array $name
     * @param int          $width
     * @param int          $height
     *
     * @return $this
     */
    public function thumbnail($name, ?int $width = null, ?int $height = null)
    {
        $this->requireImageManager();
        if (func_num_args() == 1 && is_array($name)) {
            foreach ($name as $key => $size) {
                if (count($size) >= 2) {
                    $this->thumbnails[$key] = $size;
                }
            }
        } elseif (func_num_args() == 3) {
            $this->thumbnails[$name] = [$width, $height];
        }

        return $this;
    }

    /**
     * Destroy original thumbnail files.
     *
     * @return void.
     */
    public function destroyThumbnail()
    {
        if ($this->retainable) {
            return;
        }

        foreach ($this->thumbnails as $name => $_) {
            /*  Refactoring actual remove lofic to another method destroyThumbnailFile()
            to make deleting thumbnails work with multiple as well as
            single image upload. */

            if (is_array($this->original)) {
                if (empty($this->original)) {
                    continue;
                }

                foreach ($this->original as $original) {
                    $this->destroyThumbnailFile($original, $name);
                }
            } else {
                $this->destroyThumbnailFile($this->original, $name);
            }
        }
    }

    /**
     * Remove thumbnail file from disk.
     *
     * @return void.
     */
    public function destroyThumbnailFile($original, $name)
    {
        if (!is_string($original) || $original === '' || \Illuminate\Support\Facades\URL::isValidUrl($original)) return;
        $this->deleteStoredFile($this->thumbnailPath($original, $name));
    }

    protected function thumbnailPath(string $original, string $name): string
    {
        $this->assertSafePath($name);
        if (str_contains($name, '/')) throw new \InvalidArgumentException('Thumbnail names must be filenames.');
        $ext = pathinfo($original, PATHINFO_EXTENSION);
        return Str::replaceLast('.'.$ext, '', $original).'-'.$name.'.'.$ext;
    }

    /**
     * Upload file and delete original thumbnail files.
     *
     * @param UploadedFile $file
     *
     * @return $this
     */
    protected function uploadAndDeleteOriginalThumbnail(UploadedFile $file)
    {
        foreach ($this->thumbnails as $name => $size) {
            $this->requireImageManager();
            $path = $this->thumbnailPath($this->uploadPath(), $name);
            // Never overwrite a pre-existing thumbnail not owned by this upload.
            if ($this->storedFileExists($path)) {
                throw new \Encore\Admin\Form\UploadException('Upload thumbnail already exists.');
            }
            $manager = new ImageManager(new Driver());
            $image = $manager->read($file->getRealPath());
            $action = $size[2] ?? 'scale';
            $image->$action($size[0], $size[1])->resizeCanvas($size[0], $size[1], '#ffffff', 'center');
            $this->registerNewFile($path);
            try {
                if ($this->storage->put($path, (string) $image->encodeByExtension(pathinfo($this->name, PATHINFO_EXTENSION)), $this->storagePermission ?? []) === false) {
                    throw new \RuntimeException('Storage write failed.');
                }
            } catch (\Throwable $exception) {
                throw new \Encore\Admin\Form\UploadException('Upload thumbnail write failed.');
            }

        }

        return $this;
    }
}
