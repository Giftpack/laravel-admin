<?php

namespace Encore\Admin\Form\Field;

use Encore\Admin\Form\Field;

class Display extends Field
{
    public function render()
    {
        $original = $this->value;
        if (!$this->callback) {
            $this->value = e($this->value());
        }
        try {
            return parent::render();
        } finally {
            $this->value = $original;
        }
    }
}
