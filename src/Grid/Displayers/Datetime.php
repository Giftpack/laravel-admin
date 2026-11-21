<?php

namespace Encore\Admin\Grid\Displayers;

use Encore\Admin\Admin;

class Datetime extends AbstractDisplayer
{
    public function display($format = '')
    {
        return Admin::component('admin::grid.inline-edit.datetime', [
            'key'      => $this->getKey(),
            'value'    => $this->getValue(),
            'display'  => $this->getValue(),
            'name'     => $this->getPayloadName(),
            'resource' => $this->getResource(),
            'legacyTrigger' => "ie-trigger-{$this->getClassName()}",
            'trigger'  => "ie-trigger-{$this->getControlId()}",
            'target'   => "ie-template-{$this->getControlId()}",

            'format'   => $format,
            'locale'   => config('app.locale'),
        ]);
    }
}
