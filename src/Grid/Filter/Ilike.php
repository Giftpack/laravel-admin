<?php

namespace Encore\Admin\Grid\Filter;

class Ilike extends Like
{
    protected $query = 'whereLike';

    public function condition($inputs)
    {
        if ($this->ignore) { return; }

        $value = \Illuminate\Support\Arr::get($inputs, $this->column);
        if (!is_scalar($value) || $value === '') {
            return;
        }
        $this->value = $value;

        return $this->buildCondition($this->column, '%'.(string) $value.'%', false);
    }
}
