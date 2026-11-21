<?php

namespace Encore\Admin\Grid\Displayers;

use Encore\Admin\Admin;

class Textarea extends AbstractDisplayer
{
    public function display($rows = 5)
    {
        return Admin::component('admin::grid.inline-edit.textarea', [
            'key'      => $this->getKey(),
            'value'    => $this->getValue(),
            'display'  => $this->getValue(),
            'name'     => $this->getPayloadName(),
            'resource' => $this->getResource(),
            'legacyTrigger' => "ie-trigger-{$this->getClassName()}",
            'trigger'  => "ie-trigger-{$this->getControlId()}",
            'target'   => "ie-template-{$this->getControlId()}",
            'rows'     => $rows,
        ]);
    }
}
