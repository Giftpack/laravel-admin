<?php

namespace Encore\Admin\Auth\Database;

/** Keep the row and all grant/pivot cleanup on its selected connection atomic. */
trait TransactionalGrants
{
    protected function newBelongsToMany(
        \Illuminate\Database\Eloquent\Builder $query,
        \Illuminate\Database\Eloquent\Model $parent,
        $table, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey, $relationName = null
    ) {
        return new GrantRelation($query, $parent, $table, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey, $relationName);
    }

    public function delete()
    {
        $existed = $this->exists;
        try {
            return $this->getConnection()->transaction(fn () => parent::delete());
        } catch (\Throwable $exception) {
            $this->exists = $existed;
            throw $exception;
        }
    }
}
