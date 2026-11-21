<?php

namespace Encore\Admin\Grid\Filter;

class Date extends AbstractFilter
{
    /**
     * {@inheritdoc}
     */
    protected $query = 'whereDate';

    /**
     * @var string
     */
    protected $fieldName = 'date';

    public function condition($inputs)
    {
        $value = \Illuminate\Support\Arr::get($inputs, $this->column);
        if (!is_scalar($value) || $value === '') {
            return;
        }
        $rules = ['date' => 'date_format:Y-m-d', 'day' => 'integer|between:1,31', 'month' => 'integer|between:1,12', 'year' => 'integer|between:1,9999'];
        if (validator(['value' => $value], ['value' => $rules[$this->fieldName]])->fails()) {
            return;
        }
        return parent::condition($inputs);
    }

    /**
     * {@inheritdoc}
     */
    public function __construct($column, $label = '')
    {
        parent::__construct($column, $label);

        $this->{$this->fieldName}();
    }
}
