<?php

namespace Encore\Admin\Form\Field;

use Encore\Admin\Form\NestedForm;
use Encore\Admin\Widgets\Form as WidgetForm;
use Illuminate\Support\Arr;

class Table extends HasMany
{
    /**
     * @var string
     */
    protected $viewMode = 'table';

    /**
     * Table constructor.
     *
     * @param string $column
     * @param array  $arguments
     */
    public function __construct($column, $arguments = [])
    {
        $this->column = $column;

        if (count($arguments) == 1) {
            $this->label = $this->formatLabel();
            $this->builder = $arguments[0];
        }

        if (count($arguments) == 2) {
            list($this->label, $this->builder) = $arguments;
        }
    }

    /**
     * @return array
     */
    protected function buildRelatedForms()
    {
//        if (is_null($this->form)) {
//            return [];
//        }

        $forms = [];

        if (session()->hasOldInput($this->column)) {
            $values = old($this->column, []);
            foreach ($values as $key => $data) {
                if (($data[NestedForm::REMOVE_FLAG_NAME] ?? 0) == 1) {
                    continue;
                }

                $forms[$key] = $this->buildNestedForm($this->column, $this->builder, $key)->fill($data);
            }
        } else {
            foreach ($this->value() ?? [] as $key => $data) {
                if (isset($data['pivot'])) {
                    $relation = $this->form->model()->{$this->column}();
                    if ($relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsToMany) {
                        $key = $data['pivot'][$relation->getRelatedPivotKeyName()];
                    }
                    $data = array_merge($data, $data['pivot']);
                }
                if (is_array($data)) {
                    $forms[$key] = $this->buildNestedForm($this->column, $this->builder, $key)->fill($data);
                }
            }
        }

        return $forms;
    }

    protected function nestedValidationModel($key, array $row): \Illuminate\Database\Eloquent\Model
    {
        $parent = $this->validationModel();
        $context = $parent ? clone $parent : new class extends \Illuminate\Database\Eloquent\Model {};
        $context->exists = array_key_exists($key, (array) $this->original);
        if (method_exists($context, $this->column)) {
            $relation = $context->{$this->column}();
            if ($relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsToMany) {
                $pivotKey = $relation->getRelatedPivotKeyName();
                $context->exists = collect($this->original)->contains(fn ($record) => (string) ($record['pivot'][$pivotKey] ?? '') === (string) $key);
            }
        }
        $context->setAttribute($context->getKeyName(), $key);

        return $context;
    }

    public function prepare($input)
    {
        $form = $this->buildNestedForm($this->column, $this->builder);

        $prepare = $form->setOriginal($this->original)->prepare($input);
        foreach ($prepare as $key => &$record) {
            $original = $this->original[$key] ?? null;
            // A supplied JSON row is a partial edit; relation sync already preserves omitted pivot attributes.
            if (is_array($original) && !isset($original['pivot']) && ($record[NestedForm::REMOVE_FLAG_NAME] ?? 0) != 1) {
                $merged = $original;
                foreach ($form->fields() as $field) {
                    foreach ((array) $field->column() as $column) {
                        if (Arr::has($record, $column)) {
                            // Each declared field owns replacement/merge of its supplied value.
                            Arr::set($merged, $column, Arr::get($record, $column));
                        }
                    }
                }
                $record = $merged;
            }
        }
        unset($record);

        return collect($prepare)->reject(function ($item) {
            return $item[NestedForm::REMOVE_FLAG_NAME] == 1;
        })->map(function ($item) {
            unset($item[NestedForm::REMOVE_FLAG_NAME]);

            return $item;
        })->toArray();
    }

    protected function getKeyName()
    {
        if (is_null($this->form)) {
            return;
        }

        return 'id';
    }

    protected function buildNestedForm($column, \Closure $builder, $key = null)
    {
        $form = new NestedForm($column);

        if ($this->form instanceof WidgetForm) {
            $form->setWidgetForm($this->form);
        } else {
            $form->setForm($this->form);
        }

        $form->setKey($key);

        call_user_func($builder, $form);

        $form->hidden(NestedForm::REMOVE_FLAG_NAME)->default(0)->addElementClass(NestedForm::REMOVE_FLAG_CLASS);

        return $form;
    }

    public function render()
    {
        return parent::render();
    }
}
