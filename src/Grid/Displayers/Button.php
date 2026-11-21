<?php

namespace Encore\Admin\Grid\Displayers;

class Button extends AbstractDisplayer
{
    public function display($style = null)
    {
        $style = collect((array) $style)->map(function ($style) {
            return 'btn-'.$style;
        })->implode(' ');

        $value = $this->displayText($this->value);
        $style = e($style);

        return "<span class='btn $style'>{$value}</span>";
    }
}
