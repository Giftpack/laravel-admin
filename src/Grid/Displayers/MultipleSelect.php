<?php

namespace Encore\Admin\Grid\Displayers;

use Encore\Admin\Admin;
use Illuminate\Support\Arr;

class MultipleSelect extends AbstractDisplayer
{
    public function display($options = [])
    {
        return Admin::component('admin::grid.inline-edit.multiple-select', [
            'key'      => $this->getKey(),
            'name'     => $this->getPayloadName(),
            'value'    => json_encode($this->getValue()),
            'resource' => $this->getResource(),
            'legacyTrigger' => "ie-trigger-{$this->getClassName()}",
            'trigger'  => "ie-trigger-{$this->getControlId()}",
            'target'   => "ie-template-{$this->getControlId()}",
            'display'  => implode(';', Arr::only($options, $this->getValue())),
            'options'  => $options,
        ]);
    }
}
