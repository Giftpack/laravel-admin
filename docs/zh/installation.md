# 安裝 — 2.0 候選版

此主要版本候選尚未發布，也沒有建立標籤。以下 `^2.0` 指令僅適用於未來核准發布後；本機審查使用指向此 checkout 的 Composer path repository 並明確指定測試版本 `2.0.0`。

需求：PHP `~8.5.13`（實測8.5.13）、Laravel `^13.40`（最低13.40.0／目前鎖定13.43.0）、composer.json 所列最低修補版本的 Symfony7.4 元件、PostgreSQL15.14 或 MySQL8.4.13。未宣稱其他引擎／Laravel／Symfony 支援。需要所選資料庫的 PDO、Fileinfo、mbstring、DOM/XML；圖片轉換需要 GD/EXIF 與選用套件，XLSX 需要 ZIP 與選用套件。

**全新** Laravel13 主機先設定資料庫，再執行：

```sh
composer require encore/laravel-admin:^2.0 --with-all-dependencies
php artisan vendor:publish --tag=laravel-admin-config
php artisan vendor:publish --tag=laravel-admin-migrations
php artisan vendor:publish --tag=laravel-admin-assets
php artisan vendor:publish --tag=laravel-admin-lang
php artisan admin:install
```

安裝前檢查 config/admin.php 的模型、連線／表名、路由前綴／網域、guard、上傳磁碟。安裝會執行已發布的 migration；全新預設帳號為 `admin`／`admin`，對外開放前務必修改。產生程式位於 app/Admin，靜態資源位於 public/vendor/laravel-admin。若要自行維護視圖覆寫，可另發布 `laravel-admin-views`。

既有主機請閱讀[升級與回復](upgrade.md)，不可重建後台目錄或重新填入既有資料。一般發布保留既有檔案，強制發布會覆蓋自訂設定／視圖／資源。

選用實測套件：Intervention Image3.11.0／3.11.10、Maatwebsite Excel4.0.7／PhpSpreadsheet5.11.0、Mews Captcha3.5.1、Spatie Eloquent Sortable5.0.1、Matthias Mullie Minify1.3.76。未安裝 Intervention 仍可一般上傳；轉換／縮圖會明確回報缺少套件。README 第三方擴充、實際雲端儲存及歷史 MongoDB 整合仍是未解決前置條件，沒有認證或默認移除。

開發測試使用 BrowserKit7.3.0、PHPUnit12.5.8／12.5.45、Mockery1.6.15。舊間接依賴 Mockery1.0 在 PHP8.5 的 spy 產生器會失敗，明確的開發最低版本已排除它。使用者執行期不需安裝這些開發工具。詳見[測試說明](../../tests/README.md)。
