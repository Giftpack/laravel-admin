# 文件/图片上传

[model-form](/zh/model-form.md)通过以下的调用来生成form元素。

```php
$form->file('file_column');
$form->image('image_column');
```

## 修改存储路径或文件名

```php

// 修改上传目录
$form->image('picture')->move('public/upload/image1/');

// 使用随机生成文件名 (md5(uniqid()).extension)
$form->image('picture')->uniqueName();

// 自定义文件名
$form->image('picture')->name(function ($file) {
    return 'test.'.$file->guessExtension();
});

```

[model-form](/zh/model-form.md)支持本地和云存储的文件上传

## 本地上传

先添加存储配置，`config/filesystems.php` 添加一项`disk`:

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

设置上传的路径为`public/uploads`(public_path('uploads'))。

然后选择上传的`disk`，打开`config/admin.php`找到：

```php
    
'upload'  => [

    'disk' => 'admin',

    'directory'  => [
        'image'  => 'images',
        'file'   => 'files',
    ]
],

```

将`disk`设置为上面添加的`admin`，`directory.image`和`directory.file`分别为用`$form->image($column)`和`$form->file($column)`上传的图片和文件的上传目录。


## 云盘上传

如果需要上传到云存储，需要安装对应`laravel storage`的适配器，拿七牛云存储举例

首先安装 [zgldh/qiniu-laravel-storage](https://github.com/zgldh/qiniu-laravel-storage)

同样配置好disk，在`config/filesystems.php` 添加一项:

```php
'disks' => [
    ... ,
    'qiniu' => [
        'driver'  => 'qiniu',
        'domains' => [
            'default'   => 'xxxxx.com1.z0.glb.clouddn.com', //你的七牛域名
            'https'     => 'dn-yourdomain.qbox.me',         //你的HTTPS域名
            'custom'    => 'static.abc.com',                //你的自定义域名
         ],
        'access_key'=> '',  //AccessKey
        'secret_key'=> '',  //SecretKey
        'bucket'    => '',  //Bucket名字
        'notify_url'=> '',  //持久化处理回调地址
        'url'       => 'http://of8kfibjo.bkt.clouddn.com/',  // 填写文件访问根url
    ],
],

```

然后修改`laravel-admin`的上传配置，打开`config/admin.php`找到：

```php

'upload'  => [

    'disk' => 'qiniu',

    'directory'  => [
        'image'  => 'image',
        'file'   => 'file',
    ],
],

```

`disk`选择上面配置的`qiniu`。


### 圖片處理與檔案生命週期

原始檔案／圖片上傳不需要 Intervention。圖片轉換與縮圖需安裝 `composer require intervention/image:^3.11`、啟用 PHP GD 及需要的 JPEG／PNG／WebP 編碼支援；JPEG 自動轉正另需 EXIF。測試容器已啟用 GD 與 EXIF，消費端應自行確認。Laravel 預設圖片驗證不接受 SVG，其他格式與遠端儲存服務須另行驗證。

使用 Intervention 3 回傳圖片的操作，例如 `rotate(90)`、垂直翻轉 `flip()`、水平翻轉 `flop()`、`scale(320, 200)`、`crop(320, 200, 10, 10)` 與 `place($watermark, 'center')`。亦保留 `flip('v')`／`flip('h')`。v2 的 `insert()`／`widen()` 須改成 v3 API；不要鏈接回傳編碼物件的操作，輸出會依儲存檔名的副檔名編碼。縮圖預設保留比例，以白色補足指定尺寸。

```php
$form->image('avatar')->uniqueName()->rotate(0)->thumbnail('small', 80, 80)->removable();
$form->multipleImage('pictures')->thumbnail('small', 80, 80)->sortable()->removable();
$form->file('document')->move('documents')->rules('mimes:pdf')->storagePermission('private');
```

所有路徑相對於設定的 disk，`move('', 'name.png')` 使用該 disk 根目錄。檔名不可包含目錄、絕對路徑或目錄穿越；名稱碰撞改用唯一名稱。多檔命名回呼會逐檔執行。瀏覽器格式選項只控制呈現，接受的檔案格式必須使用 `mimes`／`mimetypes` 等伺服器驗證。

表單在資料庫提交前寫入新檔；原檔與已設定的縮圖僅在 model connection 最外層交易提交後清理。驗證或資料庫失敗會保留有效原檔，回滾時只嘗試刪除該次上傳建立的檔案。多檔上傳會追加，排序必須包含現有索引且不能重複。軟刪除保留檔案，永久刪除清理已宣告的單／多檔欄位與縮圖。`retainable()` 在替換、移除與永久刪除時保留檔案；縮圖設定須與建立時一致。外部 URL 不會被儲存清理刪除。

儲存讀寫失敗由原生 HTTP kernel 回傳通用 HTTP503。提交後刪檔失敗仍保留已成功的資料庫異動，警告記錄 disk、欄位與相對路徑供管理者清理；回滾清理失敗也記錄孤立新檔，不遮蓋原本的資料庫錯誤。若記錄服務也失效，消費端須自行盤點。資料庫與檔案系統沒有共同原子交易，程序中止、不確定的提交／網路失敗、並行同名上傳或遠端服務部分刪除仍需盤點；並行寫入請使用唯一名稱。本機與假 adapter 測試不代表任何線上雲端服務已通過驗證。


內建管理員列表的逐列 Delete 操作，只清理設定的使用者 model 之原始 avatar 儲存路徑，並在該 model connection 的永久刪除交易提交後執行。軟刪除、取消或回滾會保留檔案，不會推測其他 host model 的上傳欄位。預設使用者表單沒有宣告 avatar 縮圖或保留設定；自訂 controller 的縮圖與 `retainable()` 政策無法由通用列表操作推知，應使用共用已宣告上傳／保留政策的自訂 action。一般 Form 刪除仍依表單所設定的欄位與縮圖處理。

預覽保留絕對與根目錄相對 URL，例如 `/uploads/images/avatar.jpg`；原始儲存 key 仍相對於設定的 disk。移除檔案使用已呈現表單的 action，包含自訂個人設定端點、CSRF token 與 PUT method override。個人設定 AJAX avatar 移除回傳 fileinput 所需的 JSON，一般個人資料儲存仍保留轉址與成功通知。
