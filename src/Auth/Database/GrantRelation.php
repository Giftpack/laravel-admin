<?php

namespace Encore\Admin\Auth\Database;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class GrantRelation extends BelongsToMany
{
    public function sync($ids, $detaching = true)
    {
        return $this->getQuery()->getConnection()->transaction(fn () => parent::sync($ids, $detaching));
    }

    public function toggle($ids, $touch = true)
    {
        return $this->getQuery()->getConnection()->transaction(fn () => parent::toggle($ids, $touch));
    }
}
