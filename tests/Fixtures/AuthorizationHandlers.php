<?php

use Encore\Admin\Auth\Database\Administrator;
use Illuminate\Http\Request;

class BoundaryRow extends Encore\Admin\Actions\RowAction
{
    public static $constructed = 0;
    public function __construct() { self::$constructed++; parent::__construct(); }
    public function authorize($user, $model) { return $user->can('change') && $model->name !== 'protected'; }
    public function handle($model, Request $request) { $model->update(['name'=>'changed']); return $this->response()->success('saved'); }
}
class BoundaryBatch extends Encore\Admin\Actions\BatchAction
{
    public function handle($models, Request $request) { foreach ($models as $model) $model->update(['name'=>'changed']); return $this->response()->success('saved'); }
}
class BoundaryRecord extends Administrator
{
    public function actionAuthorize($user, $action) { return $user->can('change') && $this->name !== 'protected'; }
}
class BoundaryDefaultAction extends Encore\Admin\Actions\Action
{
    public function handle(Request $request) { Administrator::where('name','target')->update(['name'=>'changed']); return $this->response()->success('saved'); }
}
class BoundaryForm extends Encore\Admin\Widgets\Form
{
    public static $constructed = 0;
    public function __construct($data = []) { self::$constructed++; parent::__construct($data); }
    public function authorize($user) { return $user->can('change'); }
    public function handle(Request $request) { Administrator::where('name','target')->update(['name'=>'changed']); return 'saved'; }
}
class BoundarySelectable extends Encore\Admin\Grid\Selectable
{
    public static $constructed = 0;
    public function __construct(...$args) { self::$constructed++; }
    public function authorize($user) { return $user->can('browse'); }
    public function make() {}
    public function render() { return 'selected'; }
}
class BoundaryRenderable implements Illuminate\Contracts\Support\Renderable
{
    public static $constructed = 0;
    public function __construct() { self::$constructed++; }
    public function authorize($user, $key) { return $user->can('browse') && $key === 'allowed'; }
    public function render($key = null) { return 'rendered'; }
}
class BoundaryUnrelated { public function __construct() { throw new RuntimeException('Untrusted constructor executed'); } }


class BoundaryDeniedResponse extends BoundaryDefaultAction
{
    public function authorize($user, $model) { return Illuminate\Auth\Access\Response::deny('denied'); }
}
class BoundaryCollectionBatch extends BoundaryBatch
{
    public function authorize($user, Illuminate\Database\Eloquent\Collection $models) { return $user->can('change') && $models->count() > 0; }
}

class BoundaryExceptionAction extends BoundaryDefaultAction
{
    public function authorize($user, $model) { return true; }
    public function handle(Request $request) { throw new RuntimeException($request->input('password')); }
}

class BoundaryProtectedAction extends BoundaryDefaultAction
{
    protected function authorize($user, $model) { return $user->can('change'); }
}

class BoundaryModal extends Encore\Admin\Grid\Displayers\Modal
{
    public function producerUrl($class) { $this->renderable = $class; return $this->getLoadUrl(); }
}
class BoundaryExpand extends Encore\Admin\Grid\Displayers\Expand
{
    public function producerUrl($class) { $this->renderable = $class; return $this->getLoadUrl(); }
}

class BoundaryKeyRecord extends Illuminate\Database\Eloquent\Model
{
    protected $table = 'boundary_key_records';
    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $guarded = [];
    public function __construct(array $attributes = []) { $this->setConnection(config('admin.database.connection') ?: config('database.default')); parent::__construct($attributes); }
    public function actionAuthorize($user, $action) { return $user->can('change') && $this->name !== 'protected'; }
}
class BoundaryKeyRow extends Encore\Admin\Actions\RowAction
{
    public static $authorized = 0;
    public static $handled = 0;
    public function authorize($user, Illuminate\Database\Eloquent\Model $model) { self::$authorized++; return true; }
    public function handle(Illuminate\Database\Eloquent\Model $model, Request $request) { self::$handled++; $model->update(['name'=>'changed']); return $this->response()->success('saved'); }
}
class BoundaryKeyBatch extends Encore\Admin\Actions\BatchAction
{
    public static $authorized = 0;
    public static $handled = 0;
    public function authorize($user, Illuminate\Database\Eloquent\Collection $models) { self::$authorized++; return true; }
    public function handle(Illuminate\Database\Eloquent\Collection $models, Request $request) { self::$handled++; foreach($models as $model)$model->update(['name'=>'changed']); return $this->response()->success('saved'); }
}
class BoundaryKeyPolicyRow extends Encore\Admin\Actions\RowAction
{
    public function handle(Illuminate\Database\Eloquent\Model $model, Request $request) { $model->update(['name'=>'changed']); return $this->response()->success('saved'); }
}
class BoundaryMissingRow extends BoundaryKeyRow
{
    public function retrieveModel(Request $request) { return false; }
}
class BoundaryMissingBatch extends BoundaryKeyBatch
{
    public function retrieveModel(Request $request) { return new Illuminate\Database\Eloquent\Collection; }
}
