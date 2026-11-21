<?php

use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Form;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class PrecommitFormParent extends Model
{
    protected $table = 'precommit_form_parents';
    protected $guarded = [];
    public $timestamps = false;
    public $incrementing = false;

    public function owner() { return $this->belongsTo(PrecommitFormOwner::class, 'owner_id'); }
    public function subject() { return $this->morphTo(); }
    public function children() { return $this->hasMany(PrecommitFormChild::class, 'parent_id'); }
}

class PrecommitFormOwner extends Model
{
    protected $table = 'precommit_form_owners';
    protected $guarded = [];
    public $timestamps = false;
}

class PrecommitFormChild extends Model
{
    protected $table = 'precommit_form_children';
    protected $primaryKey = 'child_code';
    protected $keyType = 'string';
    protected $guarded = [];
    protected $casts = ['kv' => 'array', 'items' => 'array'];
    public $timestamps = false;
    public $incrementing = false;
}

class PrecommitFormIntegrityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->be(Administrator::first(), 'admin');
        Schema::create('precommit_form_owners', function ($table) {
            $table->increments('id');
            $table->string('label');
        });
        Schema::create('precommit_form_parents', function ($table) {
            $table->integer('id')->primary();
            $table->string('title')->nullable();
            $table->integer('owner_id')->nullable();
            $table->integer('subject_id')->nullable();
            $table->string('subject_type')->nullable();
        });
        Schema::create('precommit_form_children', function ($table) {
            $table->string('child_code')->primary();
            $table->integer('parent_id');
            $table->string('label');
            $table->integer('position')->default(0);
            $table->json('kv')->nullable();
            $table->json('items')->nullable();
        });
    }

    private function input(array $data, string $method = 'PUT'): void
    {
        $request = Request::create('/admin/precommit-forms', $method, $data, [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        Illuminate\Support\Facades\Request::swap($request);
    }

    public function testCancelledBelongsToAndMorphToAssociationRollBackAllRelatedWrites(): void
    {
        foreach (['owner', 'subject'] as $relation) {
            foreach (['POST', 'PUT'] as $method) {
                foreach ([false, true] as $existingOwner) {
                    $owner = $existingOwner ? PrecommitFormOwner::create(['label' => 'Before owner']) : null;
                    $attributes = ['id' => 1, 'title' => 'Before', 'subject_type' => PrecommitFormOwner::class];
                    $foreignKey = $relation === 'owner' ? 'owner_id' : 'subject_id';
                    if ($owner) $attributes[$foreignKey] = $owner->id;
                    if ($method === 'PUT') PrecommitFormParent::create($attributes);
                    $model = new PrecommitFormParent();
                    $model->subject_type = PrecommitFormOwner::class;
                    if ($method === 'POST' && $owner) $model->setRelation($relation, $owner);
                    $form = new Form($model);
                    $form->hidden('id');
                    $form->text('title');
                    $form->text($relation.'.label');
                    $this->input(['id' => 1, 'title' => 'After', $relation => ['label' => 'After owner']], $method);
                    $saves = 0;
                    $observedRelated = null;
                    PrecommitFormParent::saving(function ($parent) use (&$saves, &$observedRelated, $foreignKey) {
                        if (++$saves === 2) {
                            $observedRelated = PrecommitFormOwner::findOrFail($parent->$foreignKey)->label;
                            return false;
                        }
                    });
                    $failed = false;
                    try {
                        $method === 'POST' ? $form->store() : $form->update(1);
                    } catch (RuntimeException $exception) {
                        $failed = true;
                        $this->assertSame('Related record could not be saved.', $exception->getMessage());
                    } finally {
                        PrecommitFormParent::flushEventListeners();
                    }
                    $this->assertTrue($failed, $relation.' '.$method.' cancelled association rejected');
                    $this->assertSame(2, $saves);
                    $this->assertSame('After owner', $observedRelated, 'Related SQL executed before association cancellation');
                    $parent = PrecommitFormParent::find(1);
                    $this->assertSame($method === 'PUT' ? 'Before' : null, $parent?->title);
                    if ($parent) $this->assertSame($owner?->id, $parent->$foreignKey);
                    $this->assertSame($existingOwner ? 1 : 0, PrecommitFormOwner::count());
                    if ($owner) $this->assertSame('Before owner', $owner->fresh()->label);
                    PrecommitFormChild::query()->delete();
                    PrecommitFormParent::query()->delete();
                    PrecommitFormOwner::query()->delete();
                }
            }
        }
        foreach (['owner', 'subject'] as $relation) {
            PrecommitFormParent::create(['id' => 1, 'title' => 'Before', 'subject_type' => PrecommitFormOwner::class]);
            $form = new Form(new PrecommitFormParent());
            $form->text('title');
            $form->text($relation.'.label');
            $this->input(['title' => 'Allowed', $relation => ['label' => 'Allowed']]);
            $this->assertTrue($form->update(1)->getData(true)['status']);
            $parent = PrecommitFormParent::findOrFail(1);
            $this->assertSame('Allowed', $parent->title);
            $this->assertSame('Allowed', $parent->$relation->label);
            $this->assertSame(1, PrecommitFormOwner::count());
            PrecommitFormParent::query()->delete();
            PrecommitFormOwner::query()->delete();
        }

    }

    public function testDistinctNestedRowsIgnoreRemovedRecordsAndKeepLiveErrorKeys(): void
    {
        PrecommitFormParent::create(['id' => 1, 'title' => 'Before']);
        PrecommitFormChild::create(['child_code' => 'old', 'parent_id' => 1, 'label' => 'Same']);
        $make = function () {
            $form = new Form(new PrecommitFormParent());
            $form->text('title');
            $form->hasMany('children', function ($row) {
                $row->text('label')->rules('required');
            })->distinctFields(['label']);
            return $form;
        };
        $this->input(['title' => 'After', 'children' => [
            'old' => ['child_code' => 'old', 'label' => 'Same', '_remove_' => 1],
            'new_replacement' => ['child_code' => 'replacement', 'label' => 'Same'],
        ]]);
        $this->assertTrue($make()->update(1)->getData(true)['status']);
        $this->assertNull(PrecommitFormChild::find('old'));
        $this->assertSame(['replacement'], PrecommitFormParent::find(1)->children->modelKeys());
        $this->assertSame('Same', PrecommitFormChild::findOrFail('replacement')->label);
        $this->input(['title' => 'Rejected', 'children' => [
            'replacement' => ['child_code' => 'replacement', 'label' => 'Same'],
            'new_duplicate' => ['child_code' => 'duplicate', 'label' => 'Same'],
        ]]);
        $response = $make()->update(1)->getData(true);
        $this->assertFalse($response['status']);
        $this->assertArrayHasKey('children.replacement.label', $response['validation']);
        $this->assertArrayHasKey('children.new_duplicate.label', $response['validation']);
        $this->assertSame('After', PrecommitFormParent::find(1)->title);
        $this->assertSame(1, PrecommitFormChild::count());
    }

    public function testRequiredNestedCollectionsUseChildLifecycleAndKeepOptionalPartialAndZeroSemantics(): void
    {
        PrecommitFormParent::create(['id' => 1, 'title' => 'Before']);
        PrecommitFormChild::create(['child_code' => 'old', 'parent_id' => 1, 'label' => 'Keep', 'kv' => ['stay' => 'value'], 'items' => ['kept']]);
        $make = function ($required = true) {
            $form = new Form(new PrecommitFormParent());
            $form->text('title');
            $form->hasMany('children', function ($row) use ($required) {
                $row->text('label')->rules('required');
                $row->number('position');
                $row->keyValue('kv')->rules($required ? 'required' : 'min:1');
                $row->list('items')->rules($required ? 'required' : 'min:1');
            });
            return $form;
        };
        foreach ([[], ['kv' => ['keys' => [], 'values' => []], 'items' => ['values' => []]]] as $collections) {
            $this->input(['title' => 'Rejected', 'children' => ['new_required' => ['child_code' => 'required', 'label' => 'New'] + $collections]]);
            $response = $make()->update(1)->getData(true);
            $this->assertFalse($response['status']);
            foreach (['kv.keys', 'kv.values', 'items.values'] as $path) {
                $this->assertArrayHasKey('children.new_required.'.$path, $response['validation']);
            }
            $this->assertSame('Before', PrecommitFormParent::find(1)->title);
            $this->assertSame(1, PrecommitFormChild::count());
        }
        $this->input(['children' => ['old' => ['child_code' => 'old', 'position' => 3]]]);
        $this->assertTrue($make()->update(1)->getData(true)['status']);
        $this->assertSame(['stay' => 'value'], PrecommitFormChild::find('old')->kv);
        $this->assertSame(['kept'], PrecommitFormChild::find('old')->items);
        $this->input(['children' => ['new_zero' => ['child_code' => 'zero', 'label' => 'Zero', 'kv' => ['keys' => ['k'], 'values' => ['0']], 'items' => ['values' => ['0']]]]]);
        $this->assertTrue($make()->update(1)->getData(true)['status']);
        $this->assertSame(['k' => '0'], PrecommitFormChild::find('zero')->kv);
        $this->assertSame(['0'], PrecommitFormChild::find('zero')->items);
        $this->input(['children' => ['new_optional' => ['child_code' => 'optional', 'label' => 'Optional']]]);
        $this->assertTrue($make(false)->update(1)->getData(true)['status']);
        $this->assertNull(PrecommitFormChild::find('optional')->kv);
        $this->assertNull(PrecommitFormChild::find('optional')->items);
    }
}
