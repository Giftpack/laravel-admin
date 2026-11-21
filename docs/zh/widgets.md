# 前端组件

## Box组件

`Encore\Admin\Widgets\Box`用来生成box组件：

```php
use Encore\Admin\Widgets\Box;

$box = new Box('Box标题', 'Box内容');

$box->removable();

$box->collapsable();

$box->style('info');

$box->solid();

echo $box;

```

`Box::__construct($title, $content)`,`$title`参数为Box组件的标题，`$content`参数为Box的内容元素，可以是实现了`Illuminate\Contracts\Support\Renderable`接口的对象或者其他可打印变量。

`Box::title($title)`方法用来设置Box组件标题。

`Box::content($content)`方法用来设置Box组件的内容元素。

`Box::removable()`方法将Box组件设置为可关闭。

`Box::collapsable()`方法将Box组件设置为可展开和收起。

`Box::style($style)`方法设置Box组件的样式，可填值为`primary`,`info`,`danger`,`warning`,`success`,`default`。

`Box::solid()`方法为Box组件添加边框。

## Collapse组件

`Encore\Admin\Widgets\Collapse`类用来生成折叠插件：
```php
use Encore\Admin\Widgets\Collapse;

$collapse = new Collapse();

$collapse->add('Bar', 'xxxxx');
$collapse->add('Orders', new Table());

echo $collapse->render();

```

`Collapse::add($title, $content)`方法用来给折叠组件添加一个折叠项，`$title`参数设置该折叠项的标题，`$content`参数用来舍子折叠区的内用。


## 表单

`Encore\Admin\Widgets\Form`类用来快速构建表单：

```php

$form = new Form();

$form->action('example');

$form->email('email')->default('qwe@aweq.com');
$form->password('password');
$form->text('name', '输入框');
$form->url('url');
$form->color('color');
$form->map('lat', 'lng');
$form->date('date');
$form->json('val');
$form->dateRange('created_at', 'updated_at');

echo $form->render();
```
`Form::__construct($data = [])`生成一个form对象，如果传入了`$data`参数，`$data`数组中的元素将会按照`key`对应填入`form`对应name的表单中。

`Form::action($uri)`方法用来设置表单提交地址。

`Form::method($method)`方法用来设置form表单的提交方法,默认为`POST`方法。

`Form::disablePjax()` 不使用pjax方式提交表单。


## Infobox组件

`Encore\Admin\Widgets\InfoBox`类用来生成信息展示块：

```php
use Encore\Admin\Widgets\InfoBox;

$infoBox = new InfoBox('New Users', 'users', 'aqua', '/admin/users', '1024');

echo $infoBox->render();

```

效果请参考后台首页的布局文件[HomeController.php](https://github.com/z-song/laravel-admin/blob/master/src/Console/stubs/HomeController.stub)的`index()`方法中，关于`InfoBox`的部分。

## Tab组件

`Encore\Admin\Widgets\Tab`类用来生成选项卡组件：

```php
use Encore\Admin\Widgets\Tab;

$tab = new Tab();

$tab->add('Pie', $pie);
$tab->add('Table', new Table());
$tab->add('Text', 'blablablabla....');

echo $tab->render();

```

`Tab::add($title, $content)`方法用来添加一个选项卡，`$title`为选项标题，`$content`选项卡内容。

## Table

`Encore\Admin\Widgets\Table`类用来生成表格：

```php
use Encore\Admin\Widgets\Table;

// table 1
$headers = ['Id', 'Email', 'Name', 'Company'];
$rows = [
    [1, 'labore21@yahoo.com', 'Ms. Clotilde Gibson', 'Goodwin-Watsica'],
    [2, 'omnis.in@hotmail.com', 'Allie Kuhic', 'Murphy, Koepp and Morar'],
    [3, 'quia65@hotmail.com', 'Prof. Drew Heller', 'Kihn LLC'],
    [4, 'xet@yahoo.com', 'William Koss', 'Becker-Raynor'],
    [5, 'ipsa.aut@gmail.com', 'Ms. Antonietta Kozey Jr.'],
];

$table = new Table($headers, $rows);

echo $table->render();

// table 2
$headers = ['Keys', 'Values'];
$rows = [
    'name'   => 'Joe',
    'age'    => 25,
    'gender' => 'Male',
    'birth'  => '1989-12-05',
];

$table = new Table($headers, $rows);

echo $table->render();

```



### PHP 8.5 / Laravel 13 請求與步驟狀態

步驟表單現在同時產生儲存在 session 的 `_step_context` 與已簽署的 widget `_context`；自訂模板需保留這兩個隱藏欄位。驗證規則建立前，伺服器會還原已核准的步驟清單、目前步驟與導向 URL，不採信客戶端修改的 `_url`、`_current`、`_steps`。`next($data)` 保留傳入的核准資料；驗證失敗不清除先前步驟。尚未儲存的步驟仍可使用建構子預設資料。

流程資料改存於依伺服器 URL 與步驟清單區隔的 `admin_steps`。`clear()` 只移除該流程及其導覽 context，保留其他流程及主程式 session（包含主程式自己的 `steps`）。舊的未區隔 `steps` 資料不自動搬移，升級時需重新顯示步驟表單。預設 PHP session 與明確選用的 JSON session 皆有驗證，沒有進行使用端 session 格式遷移。

Admin bootstrap middleware 在每次完成或失敗後還原頁面 scripts、styles、標題、導覽與選單；應用程式註冊的擴充 callback 與基礎 assets 仍保留。Show 內建 label、badge、link 會轉義直接儲存的文字；明確自訂 callback HTML 與 `unescape()` 維持信任輸出，URL 屬性另外轉義。關閉 debug 時只顯示一般失敗提示，debug 詳情也會轉義。PJAX 保留失敗 HTTP 狀態、重新導向、JSON、下載及串流；找不到容器或 selector 無效時保留完整 HTML 供導覽回退。


流程身分包含完整的伺服器步驟鍵與表單類別對應，所以同一個 URL 上不同流程可重複使用步驟名稱。若同一份定義需要獨立的多個副本，請在 `MultipleSteps` 建構子或 `make()` 的第三個參數傳入不同且穩定的伺服器 ID，例如 `MultipleSteps::make($steps, $current, 'billing')` 與 `'shipping'`，後續請求沿用相同 ID。直接建立跨類別 StepForm 時，所有步驟須使用相同的 `setWorkflow('stable-server-id')`。沒有獨立 ID 的相同定義會共用一個流程，不宣稱自動隔離所有物件實例。
