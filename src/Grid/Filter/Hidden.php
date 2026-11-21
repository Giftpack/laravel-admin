<?php

namespace Encore\Admin\Grid\Filter;

class Hidden extends AbstractFilter
{
    /**
     * @var string
     */
    protected $name;

    /**
     * @var string
     */
    protected $value;

    /**
     * Hidden constructor.
     *
     * @param string $name
     * @param string $value
     */
    public function __construct($name, $value)
    {
        $this->name = $name;

        $this->value = $value;
    }

    /**
     * {@inheritdoc}
     */
    public function condition($inputs)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function render()
    {
        $name = e($this->name);
        $value = e($this->value);

        return "<input type='hidden' name='$name' value='$value'>";
    }
}
