<?php

namespace Encore\Admin\Grid\Displayers;

use Encore\Admin\Admin;
use Illuminate\Support\Arr;

class Select extends AbstractDisplayer
{
    public function display($options = [])
    {
        return Admin::component('admin::grid.inline-edit.select', [
            'key'      => $this->getKey(),
            'value'    => $this->getValue(),
            'display'  => Arr::get($options, $this->getValue(), ''),
            'name'     => $this->getPayloadName(),
            'resource' => $this->getResource(),
            'legacyTrigger' => "ie-trigger-{$this->getClassName()}",
            'trigger'  => "ie-trigger-{$this->getControlId()}",
            'target'   => "ie-template-{$this->getControlId()}",
            'options'  => $options,
        ]);
    }
}
