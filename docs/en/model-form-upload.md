# File/Image upload

[model-form](/en/model-form.md) can build file and image upload field with following codes

```php
$form->file('file_column');
$form->image('image_column');
```

### Change store path and name

```php

// change upload path
$form->image('picture')->move('public/upload/image1/');

// use a unique name (md5(uniqid()).extension)
$form->image('picture')->uniqueName();

// specify filename
$form->image('picture')->name(function ($file) {
    return 'test.'.$file->guessExtension();
});

```

[model-form](/en/model-form.md) both support for local and cloud storage upload

### Upload to local

first add storage configuration, add a disk in `config/filesystems.php`:

```php

'disks' => [
    ... ,

    'admin' => [
        'driver' => 'local',
        'root' => public_path('uploads'),
        'visibility' => 'public',
        'url' => env('APP_URL').'/uploads',
    ],
],

```

set upload path to `public/upload`(public_path('upload')).

And then in `config/admin.php` select the `disk` set up above：

```php

'upload'  => [

    'disk' => 'admin',

    'directory'  => [
        'image'  => 'image',
        'file'   => 'file',
    ],
],

```

Set `disk` to the` admin` that you added above,`directory.image` and `directory.file` is the upload path for `$form->image($column)` and `$form->file($column)`.

`host` is url prefix for your uploaded files.


### Upload to cloud

If you need to upload to the cloud storage, need to install a driver which supports `flysystem` adapter, take `qiniu` cloud storage as example.

first install [zgldh/qiniu-laravel-storage](https://github.com/zgldh/qiniu-laravel-storage).

Also configure the disk, in the `config/filesystems.php` add an item:

```php
'disks' => [
    ... ,
    'qiniu' => [
        'driver'  => 'qiniu',
        'domains' => [
            'default'   => 'xxxxx.com1.z0.glb.clouddn.com', 
            'https'     => 'dn-yourdomain.qbox.me',       
            'custom'    => 'static.abc.com',              
         ],
        'access_key'=> '',  //AccessKey
        'secret_key'=> '',  //SecretKey
        'bucket'    => '',  //Bucket
        'notify_url'=> '',  //
        'url'       => 'http://of8kfibjo.bkt.clouddn.com/',
    ],
],

```

Then modify the upload configuration of `laravel-admin` and open `config/admin.php` to find:

```php

'upload'  => [

    'disk' => 'qiniu',

    'directory'  => [
        'image'  => 'image',
        'file'   => 'file',
    ],
],

```

Select the above configuration` qiniu` for `disk`

### Image processing and file lifetime

Plain file/image uploads remain available without Intervention. For transformations and thumbnails install `composer require intervention/image:^3.11`, enable PHP GD, and enable GD's JPEG/PNG/WebP codecs for those formats. EXIF enables automatic JPEG orientation; without it metadata orientation is not applied. The disposable acceptance image includes GD and EXIF. SVG is excluded by Laravel's default image validation. Other formats and remote providers need their own host verification.

Use Intervention 3 image-returning methods, for example `rotate(90)`, `flip()` (vertical), `flop()` (horizontal), `scale(320, 200)`, `crop(320, 200, 10, 10)` and `place($watermark, 'center')`. Explicit legacy `flip('v')` and `flip('h')` are supported. v2-only methods such as `insert()`/`widen()` must migrate to v3 equivalents. Encoding runs once after transformations and follows the stored extension; do not chain encoder-returning methods. Thumbnails also encode by their stored extension, preserve aspect ratio by default, and pad to their configured dimensions with white.

```php
$form->image('avatar')->uniqueName()->rotate(0)->thumbnail('small', 80, 80)->removable();
$form->multipleImage('pictures')->thumbnail('small', 80, 80)->sortable()->removable();
$form->file('document')->move('documents')->rules('mimes:pdf')->storagePermission('private');
```

Paths are relative to the configured disk; `move('', 'name.png')` selects that disk's root. Names must be filenames and traversal/absolute paths are rejected. Collisions receive unique names. Multiple upload name callbacks run for each file. Browser file-type options are presentation settings; use server validation rules such as `mimes`/`mimetypes` for acceptance.

Form uploads write new files before the database commit. Original files and configured thumbnails are removed only after the model connection's outermost transaction commits. A validation/database failure retains valid originals; rollback attempts to remove only paths created by that upload. Multiple uploads append; sorting requires an exact permutation of existing indexes. Soft deletion retains files; hard deletion removes declared single/multiple upload fields and their configured thumbnails. `retainable()` preserves files during replacement, removal and hard deletion. The field's thumbnail definitions must match those used to create the stored thumbnails. File pickers/external URLs remain references and external URLs are never deleted by this storage cleanup.

Storage read/write failures return a generic HTTP503 through the native kernel. Failed cleanup after commit leaves the successful database change in place; a warning identifies the disk, field and relative owned path for host reconciliation. Failed rollback cleanup similarly retains an orphan and logs its ownership without masking the original database error. If host logging is also unavailable, hosts must reconcile storage independently. Filesystems and databases do not share an atomic transaction: process termination, ambiguous database commit/network failure, concurrent same-name uploads, or a provider deleting only part of a group require host reconciliation. Use unique names for concurrent writers. These local/fake-adapter checks do not certify live cloud storage.


The built-in administrator grid row Delete action cleans the configured user model's raw stored avatar after a successful hard-delete commit on that model's connection. Soft deletion retains it, cancellation/rollback retains it, and unrelated host models are not inspected for uploads. The default user form declares no avatar thumbnails or retention override. A custom controller's thumbnail definitions or `retainable()` setting cannot be inferred by the generic row action; use a custom action sharing your declared upload/retention policy for those cases. The ordinary declared Form deletion workflow still uses its configured fields and thumbnails.

File previews preserve absolute and root-relative disk URLs (for example `/uploads/images/avatar.jpg`) while raw storage keys remain relative to the configured disk. Upload removal uses the rendered form action, including a custom profile endpoint, with its CSRF token and PUT method override. Profile AJAX avatar deletion returns the JSON expected by the fileinput plugin; ordinary profile saves keep their redirect and success notification.
