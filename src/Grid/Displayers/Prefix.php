<?php

namespace Encore\Admin\Grid\Displayers;

class Prefix extends AbstractDisplayer
{
    public function display($prefix = null, $delimiter = '&nbsp;')
    {
        if ($prefix instanceof \Closure) {
            $prefix = $this->callModelCallback($prefix, [$this->getValue(), $this->getColumn()->getOriginal()]);
        }

        return <<<HTML
{$prefix}{$delimiter}{$this->getValue()}
HTML;
    }
}
