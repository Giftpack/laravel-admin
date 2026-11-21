<?php

use Illuminate\Database\Eloquent\Model;
use Encore\Admin\Grid;

class NestedParent extends Model
{
    protected $table='nested_parents'; protected $primaryKey='code'; public $incrementing=false; protected $keyType='string'; public $timestamps=false; protected $guarded=[];
    protected $casts=['settings'=>'array','rows'=>'array'];
    public function children() { return $this->hasMany(NestedChild::class,'parent_code','code')->orderBy('position')->orderBy('child_code'); }
    public function notes() { return $this->morphMany(NestedNote::class,'subject',null,null,'code')->orderBy('position'); }
    public function tags() { return $this->belongsToMany(NestedTag::class,'nested_pivots','parent_code','tag_code','code','tag_code')->withPivot('weight')->orderBy('nested_pivots.weight'); }
    public function owner() { return $this->belongsTo(NestedTag::class,'owner_code','tag_code'); }
}
class NestedChild extends Model
{
    protected $table='nested_children'; protected $primaryKey='child_code'; public $incrementing=false; protected $keyType='string'; public $timestamps=false; protected $guarded=[];
    protected $casts=['meta'=>'array'];
}
class NestedNote extends Model
{
    protected $table='nested_notes'; public $timestamps=false; protected $guarded=[];
}
class NestedTag extends Model
{
    protected $table='nested_tags'; protected $primaryKey='tag_code'; public $incrementing=false; protected $keyType='string'; public $timestamps=false; protected $guarded=[];
}
class NestedTagPicker extends Grid\Selectable
{
    public $model=NestedTag::class;
    public function make() { $this->model()->where('visible',1); $this->column('tag_code'); $this->column('label'); }
    public function authorize($user) { return true; }
}

class NestedOtherChild extends NestedChild { protected $connection='other'; }
class NestedOtherParent extends NestedParent
{
    public function children() { return $this->hasMany(NestedOtherChild::class,'parent_code','code'); }
}
class NestedDeniedPicker extends NestedTagPicker
{
    public function authorize($user) { return false; }
}

class NestedPivot extends Illuminate\Database\Eloquent\Relations\Pivot {}
class NestedPivotParent extends NestedParent
{
    public function tags() { return parent::tags()->using(NestedPivot::class); }
}
class NestedCastPivot extends NestedPivot
{
    protected $casts=['day'=>'date','flag'=>'boolean','amount'=>'decimal:2','payload'=>'array'];
    public function setNoteAttribute($value) { $this->attributes['note']=strtolower($value); }
}
class NestedCastPivotParent extends NestedParent
{
    public function tags() { return parent::tags()->using(NestedCastPivot::class)->withPivot('day','flag','amount','payload','note'); }
}

class NestedIncrementingParent extends NestedParent
{
    public function children() { return $this->hasMany(NestedNote::class,'subject_id','code')->whereNull('subject_type')->orderBy('id'); }
}
