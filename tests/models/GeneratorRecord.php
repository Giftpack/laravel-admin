<?php

namespace Tests\Models;

use Illuminate\Database\Eloquent\Model;

class GeneratorRecord extends Model
{
    protected $connection = 'generator';
    protected $primaryKey = 'record_key';
    protected $guarded = [];
    protected $casts = ['enabled' => 'boolean'];

    const CREATED_AT = 'recorded_at';
    const UPDATED_AT = 'changed_at';

    public function getTable()
    {
        return config('generator.table', 'generator_records');
    }
}
