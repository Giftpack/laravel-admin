<?php

namespace Encore\Admin\Grid\Displayers;

use Encore\Admin\Admin;
use Encore\Admin\Grid\Simple;
use Illuminate\Contracts\Support\Renderable;

class Expand extends AbstractDisplayer
{
    protected $renderable;

    public function display($callback = null, $isExpand = false)
    {
        $html = '';
        $async = false;
        $loadGrid = false;

        if (is_subclass_of($callback, Renderable::class)) {
            $this->renderable = $callback;
            $async = true;
            $loadGrid = is_subclass_of($callback, Simple::class);
        } else {
            if (!$callback instanceof \Closure) {
                throw new \InvalidArgumentException('Expand requires a model-bound closure or a renderable class.');
            }
            $html = $this->callModelCallback($callback, [$this->row]);
        }

        return Admin::component('admin::components.column-expand', [
            'key'           => $this->getKey(),
            'url'           => $this->getLoadUrl(),
            'name'          => $this->getControlId(),
            'html'          => $html,
            'value'         => $this->value,
            'async'         => $async,
            'expand'        => $isExpand,
            'loadGrid'      => $loadGrid,
            'elementClass'  => "grid-expand-{$this->grid->getGridRowName()}",
        ]);
    }

    /**
     * @param int $multiple
     *
     * @return string
     */
    protected function getLoadUrl()
    {
        $renderable = str_replace('\\', '_', $this->renderable);

        if (!$this->renderable) {
            return '';
        }
        $_context = \Encore\Admin\Security\HandlerContext::issue('renderable', $this->renderable, ['key' => (string) $this->getKey()]);

        return route(admin_get_route('handle-renderable'), compact('renderable', '_context'));
    }
}
