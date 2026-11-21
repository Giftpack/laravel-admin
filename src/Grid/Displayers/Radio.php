<?php

namespace Encore\Admin\Grid\Displayers;

use Encore\Admin\Admin;
use Illuminate\Support\Arr;

class Radio extends AbstractDisplayer
{
    public function display($options = [])
    {
        return Admin::component('admin::grid.inline-edit.radio', [
            'key'      => $this->getKey(),
            'name'     => $this->getPayloadName(),
            'value'    => $this->getValue(),
            'resource' => $this->getResource(),
            'legacyTrigger' => "ie-trigger-{$this->getClassName()}",
            'trigger'  => "ie-trigger-{$this->getControlId()}",
            'target'   => "ie-template-{$this->getControlId()}",
            'display'  => Arr::get($options, $this->getValue(), ''),
            'options'  => $options,
        ]);
    }
}
