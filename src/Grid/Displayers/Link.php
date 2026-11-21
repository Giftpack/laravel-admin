<?php

namespace Encore\Admin\Grid\Displayers;

class Link extends AbstractDisplayer
{
    public function display($callback = '', $target = '_blank')
    {
        if ($callback instanceof \Closure) {
            $href = $this->callModelCallback($callback, [$this->row]);
        } else {
            $href = $callback ?: $this->value;
        }

        $href = $this->escapeUrl($href);
        $target = e($target);
        $text = $this->displayText($this->value);

        return "<a href='$href' target='$target' rel='noopener noreferrer'>{$text}</a>";
    }
}
