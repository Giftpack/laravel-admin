<?php

use Encore\Admin\Actions\RowAction;
use Encore\Admin\Actions\BatchAction;
use Encore\Admin\Grid;
use Encore\Admin\Form;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class InteractionRecord extends Model
{
    protected $table = 'interaction_records';
    public $timestamps = false;
    protected $guarded = [];
    protected function casts(): array { return ['files'=>'array']; }
    public function tags() { return $this->belongsToMany(InteractionTag::class, 'interaction_record_tags', 'record_id', 'tag_id'); }
    public function owner() { return $this->belongsTo(InteractionTag::class, 'owner_id'); }
    public function actionAuthorize($user, $action) { return (int) $this->getKey() !== 3; }
}
class InteractionTag extends Model
{
    protected $table = 'interaction_tags';
    public $timestamps = false;
    protected $guarded = [];
}
class InteractionPicker extends Grid\Selectable
{
    public $model = InteractionTag::class;
    public static function display() { return function ($value) { return is_array($value) ? implode('; ', array_column($value, 'name')) : (InteractionTag::find($value)?->name ?? 'Choose'); }; }
    public function make() { $this->column('id'); $this->column('name'); }
    public function authorize($user, $model = null) { return $user->username !== 'denied'; }
}
class InteractionDetail implements Illuminate\Contracts\Support\Renderable
{
    public function authorize($user, $key = null) { return $user->username !== 'denied'; }
    public function render($key = null) { return '<strong>Loaded detail '.e($key).'</strong>'; }
}
class InteractionRename extends RowAction
{
    public $name = 'Rename safely';
    public function authorize($user, $model = null) { return $user->username !== 'denied' && (!$model || (int) $model->getKey() !== 3); }
    public function form($model) { $this->text('title', 'Title')->rules('required|min:3', ['required'=>'unsafe :input', 'min'=>'unsafe :input']); $this->password('password', 'Password')->rules('required|min:8'); }
    public function handle(Model $model, Request $request) { $model->update(['title'=>$request->input('title')]); return $this->response()->success('Saved')->refresh(); }
}
class InteractionFail extends RowAction
{
    public $name = 'Failure';
    public function authorize($user, $model = null) { return $user->username !== 'denied'; }
    public function handle(Model $model, Request $request) { throw new RuntimeException('synthetic-private-handler-value'); }
}
class InteractionBatch extends BatchAction
{
    public $name = 'Mark selected';
    public function authorize($user, $model = null) { return $user->username !== 'denied'; }
    public function dialog() { $this->confirm('Mark selected?'); }
    public function handle(Illuminate\Database\Eloquent\Collection $models, Request $request) { foreach ($models as $model) $model->update(['active'=>1]); return $this->response()->success('Marked')->refresh(); }
}
class InteractionController extends Illuminate\Routing\Controller
{
    public static function form(): Form
    {
        $form = new Form(new InteractionRecord());
        $form->text('title')->rules('required|min:3');
        $form->text('note');
        $form->switch('active');
        $form->select('owner_id')->options(InteractionTag::pluck('name','id'));
        $form->belongsToMany('tags', InteractionPicker::class, 'Tags');
        $form->file('file')->rules('nullable|mimes:txt|max:10');
        $form->multipleFile('files')->rules('nullable');
        return $form;
    }
    public function update($id) { if (config('fixture.grid_audit')) file_put_contents('/tmp/grid-request-fields.jsonl', json_encode(['id'=>(string)$id,'path'=>request()->path(),'fields'=>array_keys(request()->except(['_token','_method','_edit_inline'])),'files'=>array_keys(request()->allFiles())]).PHP_EOL, FILE_APPEND); abort_if(Encore\Admin\Facades\Admin::user()->username === 'denied',403); return static::form()->update($id); }
    public function store() { abort_if(Encore\Admin\Facades\Admin::user()->username === 'denied',403); return static::form()->store(); }
    public function index(Encore\Admin\Layout\Content $content)
    {
        return $content->header('Grid interaction fixture')->body($this->grid());
    }
    public function grid(): Grid
    {
        $grid = new Grid(new InteractionRecord());
        $grid->model()->orderBy('id');
        $grid->column('id');
        $grid->column('title')->text();
        $grid->column('note')->textarea();
        $grid->column('active')->switch();
        $grid->column('owner_id')->belongsTo(InteractionPicker::class);
        $grid->column('tags')->belongsToMany(InteractionPicker::class);
        $grid->column('file')->upload();
        $grid->column('files')->uploadMany();
        $grid->column('detail')->modal('Async detail', InteractionDetail::class);
        $grid->column('expanded')->expand(InteractionDetail::class);
        $grid->column('callback')->display(function () { return '<b>Callback '.e($this->title).'</b>'; });
        $grid->actions(function ($actions) { $actions->disableDelete(); $actions->disableEdit(); $actions->disableView(); $actions->add(new InteractionRename()); $actions->add(new InteractionFail()); $actions->add(new InteractionWildcardValidation()); $actions->add(new InteractionForeignValidation()); });
        $grid->batchActions(function ($actions) { $actions->disableDelete(); $actions->add(new InteractionBatch()); });
        $grid->quickCreate(function ($create) { $create->text('title', 'New title'); $create->text('note', 'New note'); });
        $grid->quickSearch('title');
        $grid->paginate(2);
        $grid->fixHeader();
        if (request('fixed')) $grid->fixColumns(1, -1);
        $grid->enableHotKeys();
        return $grid;
    }
}

class InteractionOtherRecord extends InteractionRecord
{
    public function tags() { return $this->belongsToMany(InteractionTag::class, 'other_record_tags', 'other_record_id', 'other_tag_id'); }
}
class InteractionManyProbe extends Grid\Displayers\BelongsToMany
{
    public function originalData() { return $this->getOriginalData(); }
}
class InteractionMarker extends Grid\Displayers\AbstractDisplayer
{
    public function display($prefix = '') { return $prefix.$this->row->title.':'.$this->value; }
}

class InteractionWildcardValidation extends RowAction
{
    public $name = 'Validate wildcard';
    public static $handled = 0;
    public function authorize($user, $model = null) { return $user->username !== 'denied'; }
    public function form($model)
    {
        $this->text('payload', 'Payload')->validator(function ($input) {
            $data = json_decode($input['payload'] ?? '', true);
            if (!empty($data['declared_failure'])) return validator($input, ['payload'=>'max:1']);
            return validator(is_array($data) ? $data : [], ['rows'=>'required|array','rows.*.title'=>'required|min:3'], ['*'=>'Unsafe custom validator text']);
        });
    }
    public function handle(Model $model, Request $request) { static::$handled++; $model->update(['title'=>'Validation corrected']); return $this->response()->success('Saved')->refresh(); }
}
class InteractionForeignValidation extends InteractionWildcardValidation
{
    public $name = 'Validate foreign';
    public function form($model)
    {
        $this->text('payload', 'Payload')->validator(function ($input) {
            $data = json_decode($input['payload'] ?? '', true);
            if (!empty($data['declared_failure'])) return validator($input, ['payload'=>'max:1']);
            return validator(is_array($data) ? $data : [], ['foreign_value'=>'required|min:3'], ['*'=>'Unsafe custom validator text']);
        });
    }
}

class InteractionOtherGridRecord extends InteractionRecord
{
    protected $table = 'interaction_other_records';
}
class InteractionOtherController extends InteractionController
{
    public static function form(): Form
    {
        $form = new Form(new InteractionOtherGridRecord());
        $form->text('title')->rules('required|min:3');$form->text('note');$form->switch('active');
        $form->select('owner_id')->options(InteractionTag::pluck('name','id'));
        return $form;
    }
}
class InteractionLeftPicker extends InteractionPicker
{
    public function make() { parent::make();$this->model()->where('id',1); }
}
class InteractionRightPicker extends InteractionPicker
{
    public function make() { parent::make();$this->model()->where('id',2); }
}
class InteractionLeftDetail extends InteractionDetail
{
    public function render($key = null) { return '<strong>Left detail '.e($key).'</strong>'; }
}
class InteractionRightDetail extends InteractionDetail
{
    public function render($key = null) { return '<strong>Right detail '.e($key).'</strong>'; }
}
class InteractionMultiGrid
{
    public static function make(string $side, string $prefix = '/control'): Grid
    {
        $right=$side==='right';$grid=new Grid($right?new InteractionOtherGridRecord():new InteractionRecord());
        $grid->setResource(url($prefix.($right?'/other-records':'/records')));$grid->model()->where('id',1);
        $grid->disableActions();$grid->disableBatchActions();$grid->disableRowSelector();$grid->disableFilter();$grid->disablePagination();$grid->disableCreateButton();$grid->disableExport();
        $grid->column('id');$grid->column('title')->text();$grid->column('note')->textarea();$grid->column('active')->switch();
        $grid->column('owner_id')->belongsTo($right?InteractionRightPicker::class:InteractionLeftPicker::class);
        $grid->column('detail')->modal('Own detail',$right?InteractionRightDetail::class:InteractionLeftDetail::class);
        $grid->column('expanded')->expand($right?InteractionRightDetail::class:InteractionLeftDetail::class);
        return $grid;
    }
}
