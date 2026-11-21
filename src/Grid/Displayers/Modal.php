<?php

namespace Encore\Admin\Grid\Displayers;

use Encore\Admin\Admin;
use Encore\Admin\Grid\Simple;
use Illuminate\Contracts\Support\Renderable;

class Modal extends AbstractDisplayer
{
    /**
     * @var string
     */
    protected $renderable;

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

    /**
     * @param \Closure|string $callback
     *
     * @return mixed|string
     */
    public function display($callback = null)
    {
        $title = $this->trans('title');

        if (func_num_args() == 2) {
            list($title, $callback) = func_get_args();
        } elseif (func_num_args() == 1) {
            $title = $this->trans('title');
        }

        $html = '';

        if ($async = is_subclass_of($callback, Renderable::class)) {
            $this->renderable = $callback;
        } else {
            if (!$callback instanceof \Closure) {
                throw new \InvalidArgumentException('Modal requires a model-bound closure or a renderable class.');
            }
            $html = $this->callModelCallback($callback, [$this->row]);
        }

        return Admin::component('admin::components.column-modal', [
            'url'     => $this->getLoadUrl(),
            'async'   => $async,
            'grid'    => is_subclass_of($callback, Simple::class),
            'title'   => $title,
            'html'    => $html,
            'key'     => $this->getKey(),
            'value'   => $this->value,
            'name'    => $this->getControlId(),
        ]);
    }
}
