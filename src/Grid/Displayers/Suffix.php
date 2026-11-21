<?php

namespace Encore\Admin\Grid\Displayers;

class Suffix extends AbstractDisplayer
{
    public function display($suffix = null, $delimiter = '&nbsp;')
    {
        if ($suffix instanceof \Closure) {
            $suffix = $this->callModelCallback($suffix, [$this->getValue()]);
        }

        return <<<HTML
{$this->getValue()}{$delimiter}{$suffix}
HTML;
    }
}
