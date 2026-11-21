<?php

use Illuminate\Database\Eloquent\Model;
use Encore\Admin\Traits\ModelTree;

class TreeRecord extends Model
{
    use ModelTree { boot as treeBoot; }
    protected $table = 'tree_records';
    protected $primaryKey = 'code';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;
    protected $guarded = [];
    public static $boots = 0;
    protected static function boot() { ++static::$boots; static::treeBoot(); }
    public function __construct(array $attributes = [])
    {
        $this->setParentColumn('ancestor'); $this->setTitleColumn('label'); $this->setOrderColumn('position');
        parent::__construct($attributes);
    }
}
class SoftTreeRecord extends TreeRecord { use Illuminate\Database\Eloquent\SoftDeletes; }
