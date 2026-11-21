<?php

namespace Encore\Admin\Form\Field;

use Encore\Admin\Form;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\File\UploadedFile;

trait UploadField
{
    /**
     * Upload directory.
     *
     * @var string
     */
    protected $directory = null;

    protected array $newUploadFiles = [];

    protected $storageDisk;

    /**
     * File name.
     *
     * @var null
     */
    protected $name = null;

    protected $configuredName = null;

    /**
     * Storage instance.
     *
     * @var \Illuminate\Filesystem\Filesystem
     */
    protected $storage = '';

    /**
     * If use unique name to store upload file.
     *
     * @var bool
     */
    protected $useUniqueName = false;

    /**
     * If use sequence name to store upload file.
     *
     * @var bool
     */
    protected $useSequenceName = false;

    /**
     * Retain file when delete record from DB.
     *
     * @var bool
     */
    protected $retainable = false;

    /**
     * @var bool
     */
    protected $downloadable = true;

    /**
     * Configuration for setting up file actions for newly selected file thumbnails in the preview window.
     *
     * @var array
     */
    protected $fileActionSettings = [
        'showRemove' => false,
        'showDrag'   => false,
    ];

    /**
     * Controls the storage permission. Could be 'private' or 'public'.
     *
     * @var string
     */
    protected $storagePermission;

    /**
     * @var array
     */
    protected $fileTypes = [
        'image'  => '/^(gif|png|jpe?g|svg|webp)$/i',
        'html'   => '/^(htm|html)$/i',
        'office' => '/^(docx?|xlsx?|pptx?|pps|potx?)$/i',
        'gdocs'  => '/^(docx?|xlsx?|pptx?|pps|potx?|rtf|ods|odt|pages|ai|dxf|ttf|tiff?|wmf|e?ps)$/i',
        'text'   => '/^(txt|md|csv|nfo|ini|json|php|js|css|ts|sql)$/i',
        'video'  => '/^(og?|mp4|webm|mp?g|mov|3gp)$/i',
        'audio'  => '/^(og?|mp3|mp?g|wav)$/i',
        'pdf'    => '/^(pdf)$/i',
        'flash'  => '/^(swf)$/i',
    ];

    /**
     * @var string
     */
    protected $pathColumn;

    /**
     * Initialize the storage instance.
     *
     * @return void.
     */
    protected function initStorage()
    {
        $this->disk(config('admin.upload.disk'));
    }

    /**
     * Set default options form image field.
     *
     * @return void
     */
    protected function setupDefaultOptions()
    {
        $defaults = [
            'overwriteInitial'     => false,
            'initialPreviewAsData' => true,
            'msgPlaceholder'       => trans('admin.choose_file'),
            'browseLabel'          => trans('admin.browse'),
            'cancelLabel'          => trans('admin.cancel'),
            'showRemove'           => false,
            'showUpload'           => false,
            'showCancel'           => false,
            'dropZoneEnabled'      => false,
            'deleteExtraData'      => [
                $this->formatName($this->column) => static::FILE_DELETE_FLAG,
                static::FILE_DELETE_FLAG         => '',
                '_token'                         => csrf_token(),
                '_method'                        => 'PUT',
            ],
        ];

        if ($this->form instanceof Form) {
            $defaults['deleteUrl'] = $this->form->builder()->getAction()
                ?: $this->form->resource().'/'.$this->form->model()->getKey();
        }

        $defaults = array_merge($defaults, ['fileActionSettings' => $this->fileActionSettings]);

        $this->options($defaults);
    }

    /**
     * Set preview options form image field.
     *
     * @return void
     */
    protected function setupPreviewOptions()
    {
        $initialPreviewConfig = $this->initialPreviewConfig();

        $this->options(compact('initialPreviewConfig'));
    }

    /**
     * @return array|bool
     */
    protected function guessPreviewType($file)
    {
        $filetype = 'other';
        $ext = strtok(strtolower(pathinfo($file, PATHINFO_EXTENSION)), '?');

        foreach ($this->fileTypes as $type => $pattern) {
            if (preg_match($pattern, $ext) === 1) {
                $filetype = $type;
                break;
            }
        }

        $extra = ['type' => $filetype];

        if ($filetype == 'video') {
            $extra['filetype'] = "video/{$ext}";
        }

        if ($filetype == 'audio') {
            $extra['filetype'] = "audio/{$ext}";
        }

        if ($this->downloadable) {
            $extra['downloadUrl'] = $this->objectUrl($file);
        }

        return $extra;
    }

    /**
     * Indicates if the underlying field is downloadable.
     *
     * @param bool $downloadable
     *
     * @return $this
     */
    public function downloadable($downloadable = true)
    {
        $this->downloadable = $downloadable;

        return $this;
    }

    /**
     * Allow use to remove file.
     *
     * @return $this
     */
    public function removable()
    {
        $this->fileActionSettings['showRemove'] = true;

        return $this;
    }

    /**
     * Indicates if the underlying field is retainable.
     *
     * @return $this
     */
    public function retainable($retainable = true)
    {
        $this->retainable = $retainable;

        return $this;
    }

    /**
     * Set options for file-upload plugin.
     *
     * @param array $options
     *
     * @return $this
     */
    public function options($options = [])
    {
        $this->options = array_merge($options, $this->options);

        return $this;
    }

    /**
     * Set disk for storage.
     *
     * @param string $disk Disks defined in `config/filesystems.php`.
     *
     * @throws \Exception
     *
     * @return $this
     */
    public function disk($disk)
    {
        try {
            $this->storage = Storage::disk($disk);
            $this->storageDisk = $disk;
        } catch (\Exception $exception) {
            if (!array_key_exists($disk, config('filesystems.disks'))) {
                admin_error(
                    'Config error.',
                    "Disk [$disk] not configured, please add a disk config in `config/filesystems.php`."
                );

                return $this;
            }

            throw $exception;
        }

        return $this;
    }

    /**
     * Specify the directory and name for upload file.
     *
     * @param string      $directory
     * @param null|string $name
     *
     * @return $this
     */
    public function move($directory, $name = null)
    {
        $this->dir($directory);

        $this->name($name);

        return $this;
    }

    /**
     * Specify the directory upload file.
     *
     * @param string $dir
     *
     * @return $this
     */
    public function dir($dir)
    {
        if ($dir !== null) {
            $this->directory = $dir;
        }

        return $this;
    }

    /**
     * Set name of store name.
     *
     * @param string|callable $name
     *
     * @return $this
     */
    public function name($name)
    {
        if ($name) {
            $this->name = $this->configuredName = $name;
        }

        return $this;
    }

    /**
     * Use unique name for store upload file.
     *
     * @return $this
     */
    public function uniqueName()
    {
        $this->useUniqueName = true;

        return $this;
    }

    /**
     * Use sequence name for store upload file.
     *
     * @return $this
     */
    public function sequenceName()
    {
        $this->useSequenceName = true;

        return $this;
    }

    /**
     * Get store name of upload file.
     *
     * @param UploadedFile $file
     *
     * @return string
     */
    protected function getStoreName(UploadedFile $file)
    {
        if ($this->useUniqueName) {
            return $this->generateUniqueName($file);
        }

        if ($this->useSequenceName) {
            return $this->generateSequenceName($file);
        }

        if ($this->configuredName instanceof \Closure) {
            return $this->configuredName->call($this, $file);
        }

        if (is_string($this->configuredName)) {
            return $this->configuredName;
        }

        return $file->getClientOriginalName();
    }

    /**
     * Get directory for store file.
     *
     * @return mixed|string
     */
    public function getDirectory()
    {
        if ($this->directory instanceof \Closure) {
            return call_user_func($this->directory, $this->form);
        }

        return $this->directory ?? $this->defaultDirectory();
    }

    /**
     * Set path column in has-many related model.
     *
     * @param string $column
     *
     * @return $this
     */
    public function pathColumn($column = 'path')
    {
        $this->pathColumn = $column;

        return $this;
    }

    /**
     * Upload file and delete original file.
     *
     * @param UploadedFile $file
     *
     * @return mixed
     */
    protected function upload(UploadedFile $file)
    {
        $this->renameIfExists($file);

        $path = $this->uploadPath();
        $this->registerNewFile($path);
        try {
            $result = $this->storage->putFileAs($this->getDirectory(), $file, $this->name, $this->storagePermission ?? []);
            if ($result === false) {
                throw new \RuntimeException('Upload storage write failed.');
            }
        } catch (\Throwable $exception) {
            $this->cleanupFile($path);
            throw new \Encore\Admin\Form\UploadException('Upload storage write failed.');
        }

        return $result;
    }

    /**
     * If name already exists, rename it.
     *
     * @param $file
     *
     * @return void
     */
    public function renameIfExists(UploadedFile $file)
    {
        $this->assertSafePath((string) $this->getDirectory(), true);
        $this->assertSafePath($this->name);
        if (str_contains($this->name, '/')) {
            throw new \InvalidArgumentException('Upload names must be filenames.');
        }
        if ($this->storedFileExists($this->uploadPath())) {
            $this->name = $this->generateUniqueName($file);
        }
    }

    /**
     * Get file visit url.
     *
     * @param $path
     *
     * @return string
     */
    public function objectUrl($path)
    {
        if ($this->pathColumn && is_array($path)) {
            $path = Arr::get($path, $this->pathColumn);
        }

        // Model accessors can already expose a disk URL relative to the host root.
        if (URL::isValidUrl($path) || (is_string($path) && str_starts_with($path, '/'))) {
            return $path;
        }

        if ($this->storage) {
            return $this->storage->url($path);
        }

        return Storage::disk(config('admin.upload.disk'))->url($path);
    }

    /**
     * Generate a unique name for uploaded file.
     *
     * @param UploadedFile $file
     *
     * @return string
     */
    protected function generateUniqueName(UploadedFile $file)
    {
        return md5(uniqid()).'.'.$file->getClientOriginalExtension();
    }

    /**
     * Generate a sequence name for uploaded file.
     *
     * @param UploadedFile $file
     *
     * @return string
     */
    protected function generateSequenceName(UploadedFile $file)
    {
        $index = 1;
        $extension = $file->getClientOriginalExtension();
        $original = $file->getClientOriginalName();
        $new = sprintf('%s_%s.%s', $original, $index, $extension);

        while ($this->storedFileExists("{$this->getDirectory()}/$new")) {
            $index++;
            $new = sprintf('%s_%s.%s', $original, $index, $extension);
        }

        return $new;
    }

    /**
     * Destroy original files.
     *
     * @return void.
     */
    public function destroy()
    {
        if ($this->retainable) {
            return;
        }

        if (method_exists($this, 'destroyThumbnail')) {
            $this->destroyThumbnail();
        }

        $this->deleteStoredFile($this->original);
    }

    protected function prepareUploadSafely(callable $callback)
    {
        $this->newUploadFiles = [];
        try {
            return $callback();
        } catch (\Throwable $exception) {
            foreach ($this->newUploadFiles as [$storage, $path, $disk]) $this->cleanupFile($path, $storage, $disk);
            throw $exception;
        } finally {
            $this->newUploadFiles = [];
        }
    }

    /** The field's model connection owns the lifetime, including enclosing transactions. */
    protected function uploadConnection()
    {
        return $this->form instanceof Form ? $this->form->model()->getConnection() : null;
    }

    protected function assertSafePath($path, bool $directory = false): void
    {
        if (!is_string($path) || (!$directory && $path === '') || str_starts_with($path, '/')
            || str_contains($path, "\\") || str_contains($path, "\0") || preg_match('~(^|/)\.\.?(?:/|$)|^[a-zA-Z]+:~', $path)) {
            throw new \InvalidArgumentException('Upload paths must stay within the configured disk.');
        }
    }

    protected function uploadPath(): string
    {
        return ltrim(rtrim((string) $this->getDirectory(), '/').'/'.$this->name, '/');
    }

    /** Register before the write: an adapter may leave partial data before failing. */
    protected function registerNewFile(string $path): void
    {
        $storage = $this->storage;
        $disk = $this->storageDisk;
        $this->newUploadFiles[] = [$storage, $path, $disk];
        if (($connection = $this->uploadConnection()) && $connection->transactionLevel() > 0) {
            $connection->afterRollBack(fn () => $this->cleanupFile($path, $storage, $disk));
        }
    }

    protected function storedFileExists(string $path): bool
    {
        try {
            return $this->storage->exists($path);
        } catch (\Throwable $exception) {
            throw new \Encore\Admin\Form\UploadException('Upload storage read failed.');
        }
    }

    protected function cleanupFile(string $path, $storage = null, $disk = null): void
    {
        try {
            if (($storage ?? $this->storage)->delete($path) === false) {
                throw new \RuntimeException('Storage deletion failed.');
            }
        } catch (\Throwable $exception) {
            // Cleanup cannot reverse a committed DB change or mask its original failure.
            try {
                logger()->warning('Admin upload cleanup failed; retained file requires cleanup.', ['field' => $this->column, 'disk' => $disk ?? $this->storageDisk, 'path' => $path]);
            } catch (\Throwable $loggingFailure) {
                // A host logging outage must not turn a committed save into a reported rollback.
            }
        }
    }

    protected function deleteStoredFile($path): void
    {
        if ($this->retainable || !$path || !is_string($path) || URL::isValidUrl($path)) {
            return;
        }
        $this->assertSafePath($path);
        $storage = $this->storage;
        $disk = $this->storageDisk;
        $delete = fn () => $this->cleanupFile($path, $storage, $disk);
        if (($connection = $this->uploadConnection()) && $connection->transactionLevel() > 0) {
            $connection->afterCommit($delete);
        } else {
            $delete();
        }
    }

    protected function deletingUpload(array $input): bool
    {
        return Arr::get($input, $this->column) === static::FILE_DELETE_FLAG
            && array_key_exists(static::FILE_DELETE_FLAG, $input);
    }

    public function inputShapeErrors(array $input): array
    {
        if (!Arr::has($input, $this->column)) {
            return [];
        }
        $value = Arr::get($input, $this->column);
        if ($this->deletingUpload($input)) {
            $key = $input[static::FILE_DELETE_FLAG];
            $valid = is_int($key) || is_string($key);
            if ($this instanceof MultipleFile) {
                $original = $this->original();
                $valid = $valid && array_key_exists($key, $this->pathColumn
                    ? collect($original)->keyBy($this->getRelatedKeyName())->all() : $original);
            } else {
                $valid = $valid && (string) $key === '0';
            }
        } elseif ($this instanceof MultipleFile) {
            if (is_string($value) && Arr::has($input, static::FILE_SORT_FLAG.'.'.$this->column)) {
                $valid = $this->validFileOrder($value);
            } else {
                $valid = is_array($value) && array_is_list($value);
                foreach ($valid ? $value : [] as $file) {
                    $valid = $valid && $file instanceof UploadedFile && $file->isValid();
                }
            }
        } else {
            $valid = $value === null || $value === '' || ($value instanceof UploadedFile && $value->isValid());
            if ($this->picker && is_string($value)) {
                $valid = true;
            }
        }
        return $valid ? [] : [$this->column];
    }

    /**
     * Set file permission when stored into storage.
     *
     * @param string $permission
     *
     * @return $this
     */
    public function storagePermission($permission)
    {
        $this->storagePermission = $permission;

        return $this;
    }
}
