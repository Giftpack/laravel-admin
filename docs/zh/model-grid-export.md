資料匯出
========

Grid 預設匯出含 BOM 的 UTF-8 CSV；空結果也包含標題列。預設使用欄位顯示轉換後的值：

```php
$grid->export(function ($exporter) {
    $exporter->filename('records')
        ->only(['id', 'title', 'status'])
        ->except(['status'])
        ->originalValue(['title'])
        ->title('title', fn ($label) => '資料標題');
});
```

可見欄位沿用 Grid 順序；隱藏欄位不匯出，除非使用者在欄位選擇器明確選取。`only()`、`except()` 再縮小範圍。`column($name, $callback)` 接收顯示值及原始值；`originalValue([$name])` 優先於該欄位回呼。這些是呈現設定，資料存取仍須以伺服器查詢範圍及路由權限限制。

兩處 CSV 寫入明確指定空 escape 字串，雙引號以重複雙引號表示，反斜線保留原值。這是刻意修正舊 PHP 反斜線跳脫格式：引號旁或尾端反斜線的輸出位元組會改變，使標準 CSV 讀取器能還原資料。PHP 請使用 `fgetcsv($handle, null, ',', '"', '')`。支援逗號、換行、Unicode；null 與空字串皆輸出空欄位。公式開頭的資料原樣保留，試算表匯入端應自行制定公式處理政策。

`all`、`page:N`、`selected:key,key` 共用 Grid 的伺服器條件、篩選、搜尋及已註冊排序。指定頁使用驗證後的每頁筆數，不受另一個頁碼參數影響。無效範圍／頁碼回422，停用匯出回403；選取的鍵值仍與授權查詢取交集。Grid 路由必須保留認證／權限中介層。排序相同時以主鍵穩定排序；分塊期間的並行資料變更不具快照保證。

CSV 每批讀取100筆；既有模型 collection 回呼仍套用於 `getData()` 及分塊前的完整當頁；all／selected 串流保留原本的逐欄位轉換行為。開啟、寫入、flush 或中斷失敗會擲出例外，不走正常成功 exit。開始傳送後無法撤回部分下載或保證替換 HTTP 狀態，框架可能附加錯誤內容。僅看到200不代表匯出完整；需要原子下載時應先產生並驗證檔案再提供下載。隔離測試會解析實際輸出、比對筆數，並拒絕故意中斷的串流。

選用 Excel
----------

PHP8.5／Laravel13 已實測 `maatwebsite/excel`4.0.7、PhpSpreadsheet5.11.0。請在使用端安裝，CSV 不需要此套件：

```sh
composer require maatwebsite/excel:^4.0
```

Provider 自動探索。舊2.1 `Excel::create()` API 不再支援。3.1.70 所需 PhpSpreadsheet1.30 限制 PHP<8.5，實際 Composer 無法解析；缺少套件時會以 `LogicException` 提示安裝選用依賴。

```php
namespace App\Admin\Extensions;

use Encore\Admin\Grid\Exporters\ExcelExporter;

class RecordsExport extends ExcelExporter
{
    protected ?string $fileName = 'records.xlsx';
    protected $columns = ['id' => 'ID', 'title' => '標題'];
}

$grid->exporter(new RecordsExport());
```

4.x trait 宣告了有型別的 `$fileName`；子類別請改為 `protected ?string`，或在自訂建構子設定繼承的屬性。覆寫 `query()` 時須宣告回傳型別 `Illuminate\Database\Query\Builder|Illuminate\Database\Eloquent\Builder|Illuminate\Database\Eloquent\Relations\Relation` 並保留父查詢的權限限制。

Excel 使用原始模型值與設定的標題，不套用 CSV 顯示回呼。實際重開檔案驗證 ID／rank 為數值儲存格；預設 binder 會將 `'0'` 等數字字串轉為數值、空字串／null 轉為空儲存格，XML 將 CRLF 正規化為 LF。需要不同型別可使用 Maatwebsite 自訂 binding／mapping concerns。隔離 fixture 已在兩種資料庫及目前／最低 Laravel 相依圖實際下載並重開全選、當頁、選取、空結果及特殊值工作簿；核心 CSV 測試不取代 Excel 驗證。

參考 [Laravel Excel4 文件](https://docs.laravel-excel.com/4.x/) 與 [PHP CSV 跳脫格式](https://www.php.net/manual/en/function.fputcsv.php)。
