<?php

namespace Encore\Admin\Form\Field;

use Encore\Admin\Admin;
use Encore\Admin\Grid\Selectable;

trait BelongsToRelation
{
    /**
     * @var string
     */
    protected $modalID;

    /**
     * @var string
     */
    protected $selectable;

    /**
     * BelongsToRelation constructor.
     *
     * @param string $column
     * @param array  $arguments
     */
    public function __construct($column, $arguments = [])
    {
        $this->setSelectable($arguments[0]);

        parent::__construct($column, array_slice($arguments, 1));
    }

    /**
     * @param string $selectable
     */
    protected function setSelectable($selectable)
    {
        if (!class_exists($selectable) || !is_subclass_of($selectable, Selectable::class)) {
            throw new \InvalidArgumentException(
                "[Class [{$selectable}] must be a sub class of Encore\Admin\Grid\Selectable"
            );
        }

        $this->selectable = $selectable;
    }

    /**
     * @return string
     */
    public function getSelectable()
    {
        return $this->selectable;
    }

    public function getValidator(array $input)
    {
        $base = parent::getValidator($input);
        if (!\Illuminate\Support\Arr::has($input, $this->column)) return $base;
        $value = \Illuminate\Support\Arr::get($input, $this->column);
        $ids = array_values(array_filter((array) $value, static fn ($id) => $id !== null && $id !== ''));
        $validator = $base ?: \validator([], []);
        $validator->after(function ($validator) use ($ids) {
            $picker = new $this->selectable();
            $valid = \Encore\Admin\Security\HandlerContext::authorized($picker);
            if ($valid && $ids) {
                $query = $picker->renderFormGrid($ids)->model()->getQueryBuilder();
                $valid = count($ids) === count(array_unique(array_map('strval', $ids)))
                    && $query->count() === count($ids);
            }
            if (!$valid) $validator->errors()->add($this->column, 'The selected value is unavailable.');
        });
        return $validator;
    }

    /**
     * @param int $multiple
     *
     * @return string
     */
    protected function getLoadUrl($multiple = 0)
    {
        $selectable = str_replace('\\', '_', $this->selectable);
        $args = [$multiple];

        $_context = \Encore\Admin\Security\HandlerContext::issue('selectable', str_replace('_', '\\', $selectable), ['args' => array_values($args)]);

        return route(admin_get_route('handle-selectable'), compact('selectable', 'args', '_context'));
    }

    /**
     * @return $this
     */
    public function addHtml()
    {
        $trans = [
            'choose' => admin_trans('admin.choose'),
            'cancal' => admin_trans('admin.cancel'),
            'submit' => admin_trans('admin.submit'),
        ];

        $html = <<<HTML
<div class="modal fade belongsto" id="{$this->modalID}" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content" style="border-radius: 5px;">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
        </button>
        <h4 class="modal-title">{$trans['choose']}</h4>
      </div>
      <div class="modal-body">
      <div class="loading text-center">
        <i class="fa fa-spinner fa-pulse fa-3x fa-fw"></i>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">{$trans['cancal']}</button>
        <button type="button" class="btn btn-primary submit">{$trans['submit']}</button>
      </div>
    </div>
  </div>
</div>
HTML;
        Admin::html($html);

        return $this;
    }

    /**
     * @return $this
     */
    public function addStyle()
    {
        $style = <<<'STYLE'
.belongsto.modal tr {
    cursor: pointer;
}
.belongsto.modal .box {
    border-top: none;
    margin-bottom: 0;
    box-shadow: none;
}

.belongsto.modal .loading {
    margin: 50px;
}

.belongsto.modal .grid-table .empty-grid {
    padding: 20px !important;
}

.belongsto.modal .grid-table .empty-grid svg {
    width: 60px !important;
    height: 60px !important;
}

.belongsto.modal .grid-box .box-footer {
    border-top: none !important;
}
STYLE;

        Admin::style($style);

        return $this;
    }

    /**
     * @return \Encore\Admin\Grid
     */
    protected function makeGrid()
    {
        /** @var Selectable $selectable */
        $selectable = new $this->selectable();

        abort_unless(\Encore\Admin\Security\HandlerContext::authorized($selectable), 403);

        return $selectable->renderFormGrid($this->value());
    }

    /**
     * {@inheritdoc}
     */
    public function render()
    {
        $this->modalID = sprintf('modal-selector-%s', $this->getElementClassString());

        $this->addScript()->addHtml()->addStyle();

        $this->addVariables([
            'grid'    => $this->makeGrid(),
            'options' => $this->getOptions(),
        ]);

        $this->addCascadeScript();

        return parent::fieldRender();
    }
}
