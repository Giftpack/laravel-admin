Data export
===========

The grid exports UTF-8 CSV with a BOM and a header row, including for an empty result. Exported values follow column display transformations by default. Configure the CSV exporter in the grid builder:

```php
$grid->export(function ($exporter) {
    $exporter->filename('records')
        ->only(['id', 'title', 'status'])
        ->except(['status'])
        ->originalValue(['title'])
        ->title('title', fn ($label) => 'Record title');
});
```

Visible columns retain their grid order; hidden columns are omitted unless explicitly selected through the grid column selector. `only()` and `except()` further restrict that selection. `column($name, $callback)` receives the transformed value and the original value. `originalValue([$name])` takes precedence over that field callback. These choices control presentation; use server query scopes and route authorization to restrict readable records.

Both CSV writes explicitly use an empty escape argument. Quotes are doubled, and backslashes remain literal. This deliberately changes the historical PHP backslash escape wire format for backslashes next to quotes and trailing backslashes, allowing standard CSV readers to round-trip the original values. Readers using PHP should use `fgetcsv($handle, null, ',', '"', '')`. Commas, embedded newlines and Unicode remain supported; null and empty strings both become empty CSV fields. Values beginning with spreadsheet formulas are preserved: CSV is a data export, so consumers must apply their own spreadsheet import policy.

The `all`, `page:N` and `selected:key,key` export scopes use the grid's server constraints, active filters/search and registered sorting. The explicit page uses the grid's validated page size and ignores an unrelated pagination query parameter. Malformed scopes/pages return422; disabled exports return403. Selected keys are intersected with the authorized query. Route authentication/permissions must still protect the grid endpoint. Records tied on the selected sort are ordered by primary key for deterministic chunks. Concurrent changes during offset pagination are not a database snapshot.

CSV fetches100 records per chunk. The existing model collection callback still applies to `getData()` and to the complete current page before splitting that page into chunks; all/selected streaming retains its historical per-column transformation behavior. Open/write/flush/interruption failures throw rather than reaching the exporter's normal exit. Once response bytes have been sent, HTTP cannot reliably replace the status or retract the partial download; framework error handling may append an error body. A200 header alone does not prove completion. Integrations requiring atomic downloads should generate and validate an artifact before serving it. The isolated acceptance probe verifies actual output counts and rejects the injected incomplete stream.

Optional Excel
--------------

On PHP8.5/Laravel13 the tested optional integration is `maatwebsite/excel`4.0.7 with PhpSpreadsheet5.11.0. Install it in the consuming host; it remains optional for CSV:

```sh
composer require maatwebsite/excel:^4.0
```

Laravel discovers its provider. The historical2.1 `Excel::create()` API is unsupported. The3.1.70 graph cannot resolve on PHP8.5 because its PhpSpreadsheet1.30 dependency requires PHP<8.5. An absent integration produces a `LogicException` identifying the optional dependency.

```php
namespace App\Admin\Extensions;

use Encore\Admin\Grid\Exporters\ExcelExporter;

class RecordsExport extends ExcelExporter
{
    protected ?string $fileName = 'records.xlsx';
    protected $columns = ['id' => 'ID', 'title' => 'Title'];
}

// In the grid builder:
$grid->exporter(new RecordsExport());
```

Upgrade subclasses that declare `$fileName` to `protected ?string` (or set the inherited property in their constructor). The4.x trait owns that typed property. A subclass overriding `query()` must declare `Illuminate\Database\Query\Builder|Illuminate\Database\Eloquent\Builder|Illuminate\Database\Eloquent\Relations\Relation` as its return type and retain the parent query's access restrictions. Constructor customization remains available.

Excel uses original database/model values and the configured column headings; CSV display callbacks are separate. Reopened workbooks retain numeric IDs/ranks as numeric cells. The default spreadsheet binder converts numeric strings such as `'0'` to numbers and empty strings/null to blank cells; workbook XML normalizes CRLF to LF. Use Maatwebsite's custom value binding/mapping concerns when a consumer needs different types. The real fixture verifies all/current/selected/empty/special-value workbooks on both required engines and current/minimum Laravel graphs; core CSV tests alone do not certify Excel.

See [Laravel Excel4 documentation](https://docs.laravel-excel.com/4.x/) and [PHP CSV escaping](https://www.php.net/manual/en/function.fputcsv.php).
