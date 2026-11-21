# 模型树

可以通过`model-tree`来实现一个树状组件，可以用拖拽的方式实现数据的层级、排序等操作，下面是基本的用法.

## 表结构和模型
要使用`model-tree`，要遵守约定的表结构：
```sql
CREATE TABLE `demo_categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` int(11) NOT NULL DEFAULT '0',
  `order` int(11) NOT NULL DEFAULT '0',
  `title` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
```
上面的表格结构里面有三个必要的字段`parent_id`、`order`、`title`,其它字段没有要求。

对应的模型为`app/Models/Category.php`:
```php
<?php

namespace App\Models\Demo;

use Encore\Admin\Traits\ModelTree;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use ModelTree;

    protected $table = 'demo_categories';
}
```
表结构中的三个字段`parent_id`、`order`、`title`的字段名也是可以修改的：
```php
<?php

namespace App\Models\Demo;

use Encore\Admin\Traits\ModelTree;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use ModelTree;

    protected $table = 'demo_categories';

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        
        $this->setParentColumn('pid');
        $this->setOrderColumn('sort');
        $this->setTitleColumn('name');
    }
}
```
## 使用方法
然后就是在页面中使用`model-tree`了：
```php
<?php

namespace App\Admin\Controllers\Demo;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Encore\Admin\Form;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Encore\Admin\Controllers\ModelForm;
use Encore\Admin\Tree;

class CategoryController extends Controller
{
    use ModelForm;
    
    public function index()
    {
        return Admin::content(function (Content $content) {
            $content->header('树状模型');
            $content->body(Category::tree());
        });
    }
}
```
可以通过下面的方式来修改行数据的显示：
```php
Category::tree(function ($tree) {
    $tree->branch(function ($branch) {
        $src = config('admin.upload.host') . '/' . $branch['logo'] ;
        $logo = "<img src='$src' style='max-width:30px;max-height:30px' class='img'/>";

        return "{$branch['id']} - {$branch['title']} $logo";
    });
})
```
在回调函数中返回的字符串类型数据，就是在树状组件中的每一行的显示内容，`$branch`参数是当前行的数据数组。

如果要修改模型的查询，用下面的方式
```php

Category::tree(function ($tree) {

    $tree->query(function ($model) {
        return $model->where('type', 1);
    });
    
})
```

```




### PHP 8.5 / Laravel 13 排序與刪除

排序輸入為 JSON 分支列表，每個分支含 `id` 及可選的 `children` 列表。保留原有主鍵；重複、遺失、範圍外、自身父節點及循環參照會在寫入前拒絕。每次操作重新計算前序排序，並使用模型指定的資料庫連線及自訂欄位。套件 Form 的一般資源控制器與內建選單控制器會在新增驗證前單獨處理 `_order`；權限與 CSRF 中介層仍生效。

保留原有同層排序：一般樹將 order=0 放最後；選單將 0 放最前，其餘遞增。相同 order 的順序由資料庫決定。選項縮排與根標籤不變；父節點值 `0` 保留作為根節點標記。

保留「只刪除直接子節點」的既有行為：刪除節點時批次刪除直接子節點，更深的後代保留，可能繼續參照已刪除的祖先。支援軟刪除的模型會軟刪除直接子節點；對已軟刪除的父節點強制刪除，不會清除先前已軟刪除的子節點。批次刪除不會逐一觸發子節點事件。選單會清除父節點及被刪除直接子節點的角色關聯；保留後代的角色關聯不變。父節點取消刪除或資料庫例外會在模型連線上回滾。需要遞迴刪除時，請由應用程式明確實作。
