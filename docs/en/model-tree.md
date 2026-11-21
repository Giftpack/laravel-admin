# Model-tree

Can be achieved through a `model-tree` to a tree-like components, you can drag the way to achieve the level of data, sorting and other operations, the following is the basic usage.

## Table structure and model

To use `model-tree`, you have to follow the convention of the table structure:

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
The above table structure has three necessary fields `parent_id`, `order`, `title`, and the other fields are not required.

The corresponding model is `app/Models/Category.php`:
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

Table structure in the three fields `parent_id`,` order`, `title` field name can be amended:

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
## Usage
然后就是在页面中使用`model-tree`了：

Then use `model-tree` in your page

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
            $content->header('Categories');
            $content->body(Category::tree());
        });
    }
}
```
You can modify the display of branch in the following ways:
```php
Category::tree(function ($tree) {
    $tree->branch(function ($branch) {
        $src = config('admin.upload.host') . '/' . $branch['logo'] ;
        $logo = "<img src='$src' style='max-width:30px;max-height:30px' class='img'/>";

        return "{$branch['id']} - {$branch['title']} $logo";
    });
})
```

The `$branch` parameter is array of current row data.

If you want to modify the query of the model, use the following way:
```php

Category::tree(function ($tree) {

    $tree->query(function ($model) {
        return $model->where('type', 1);
    });
    
})
```




### PHP 8.5 / Laravel 13 ordering and deletion

Reordering accepts a JSON list of branches with `id` and optional `children` lists. IDs are retained; duplicate, missing, out-of-scope, self-parent and cyclic references are rejected before any hierarchy write. Repeated operations recompute preorder positions. Tree models use their selected database connection, including custom keys and parent/title/order columns. Generic resource controllers using the package Form store path and the built-in menu controller process `_order` as a separate operation before create validation; authorization and CSRF middleware still apply.

The existing sibling policy is retained: ordinary model trees sort nonzero orders first and zero last; menu trees sort zero first, then increasing order. Equal-order siblings retain the database's unspecified tie order. Select-option indentation and root labels remain unchanged. Root parent sentinel `0` is reserved.

Deletion retains the existing **direct-child** policy: deleting a node deletes its immediate children in bulk; deeper descendants remain and can reference a deleted ancestor. Soft-delete models soft-delete direct children. Force-deleting an already soft-deleted parent does not purge its previously soft-deleted children. Bulk child deletion does not fire each child's model events. Menu deletion explicitly detaches role pivots for the parent and deleted direct children; retained deeper descendants keep their role pivots. Parent deletion cancellation or a database exception rolls back the operation on the model connection. Applications that require recursive deletion should implement that policy explicitly.
