<?php

namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExportRecord extends Model
{
    use SoftDeletes;
    protected $table = 'export_records';
    public $timestamps = false;
    protected $guarded = [];
    public function peers() { return $this->hasMany(self::class, 'tenant', 'tenant')->where('rank', '<=', 3); }
    protected static function booted(): void
    {
        static::addGlobalScope('tenant', fn ($query) => $query->where('tenant', 7));
    }
}
