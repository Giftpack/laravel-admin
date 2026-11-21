<?php

namespace Encore\Admin\Grid\Filter;

use Illuminate\Support\Arr;

class Lt extends AbstractFilter
{
    /**
     * {@inheritdoc}
     */
    protected $view = 'admin::filter.lt';

    /**
     * Get condition of this filter.
     *
     * @param array $inputs
     *
     * @return array|mixed|void
     */
    public function condition($inputs)
    {
        if ($this->ignore) { return; }

        $value = Arr::get($inputs, $this->column);

        if (!isset($value) || !is_scalar($value) || $value === '') {
            return;
        }

        $this->value = $value;

        return $this->buildCondition($this->column, '<=', $this->value);
    }
}
