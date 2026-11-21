<?php

namespace Encore\Admin\Grid\Displayers;

use Encore\Admin\Admin;

class Input extends AbstractDisplayer
{
    public function display($mask = '')
    {
        return Admin::component('admin::grid.inline-edit.input', [
            'key'      => $this->getKey(),
            'value'    => $this->getValue(),
            'display'  => $this->getValue(),
            'name'     => $this->getPayloadName(),
            'resource' => $this->getResource(),
            'legacyTrigger' => "ie-trigger-{$this->getClassName()}",
            'trigger'  => "ie-trigger-{$this->getControlId()}",
            'target'   => "ie-template-{$this->getControlId()}",
            'mask'     => $mask,
        ]);
    }
}
