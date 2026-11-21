<?php

namespace Encore\Admin\Grid\Filter;

use Illuminate\Support\Arr;

class In extends AbstractFilter
{
    /**
     * {@inheritdoc}
     */
    protected $query = 'whereIn';

    /**
     * Get condition of this filter.
     *
     * @param array $inputs
     *
     * @return mixed
     */
    public function condition($inputs)
    {
        $value = Arr::get($inputs, $this->column);

        if ($this->ignore || is_null($value) || is_object($value)) {
            return;
        }

        $this->value = array_values(array_filter((array) $value, static fn ($item) => is_scalar($item) && $item !== ''));

        return $this->buildCondition($this->column, $this->value);
    }
}
