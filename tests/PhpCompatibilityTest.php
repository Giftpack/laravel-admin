<?php

use Encore\Admin\Form;
use Encore\Admin\Form\Field\Text;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Column;
use Encore\Admin\Grid\Filter\Where;
use Encore\Admin\Grid\Row;
use Illuminate\Support\Collection;
use Tests\Models\User;

class PhpCompatibilityTest extends TestCase
{
    /** Fail on first-party warnings/deprecations without suppressing vendor diagnostics. */
    private function assertWithoutPackageDiagnostics(Closure $scenario): void
    {
        $previous = set_error_handler(function ($severity, $message, $file, $line) use (&$previous) {
            if (($severity & (E_WARNING | E_DEPRECATED | E_USER_WARNING | E_USER_DEPRECATED))
                && str_starts_with($file, dirname(__DIR__).'/src/')) {
                throw new ErrorException($message, 0, $severity, $file, $line);
            }

            return $previous ? $previous($severity, $message, $file, $line) : false;
        });
        try {
            $scenario();
        } finally {
            restore_error_handler();
        }
    }

    public function testNullableGettersAndInheritedFieldCallsPreserveValues()
    {
        $this->assertWithoutPackageDiagnostics(function () {
            $form = new Form(new User(), null);
            $field = new Text('username');
            $this->assertSame($field, $field->setForm(null));
            $this->assertNull($field->data(null));
            $field->setForm($form)->data(['' => 0, 'enabled' => false]);
            $this->assertSame(['' => 0, 'enabled' => false], $field->data());
            $this->assertSame($form->builder()->getTools(), $form->header());
            $grid = new Grid(new User(), null);
            $this->assertSame('test_users', $grid->model()->getTable());
        });
    }

    public function testTreeConstructionUsesDeclaredRequestPath()
    {
        $this->assertWithoutPackageDiagnostics(function () {
            $tree = new \Encore\Admin\Tree(null, null);
            $this->assertSame(request()->getPathInfo(), $tree->path);
            $this->assertNotNull($tree->tools);
        });
    }

    public function testNullablePathAndJsonOptionScalarsRemainData()
    {
        $this->assertWithoutPackageDiagnostics(function () {
            $this->assertSame('/admin', admin_base_path(null));
            $this->assertSame('/admin', admin_base_path(''));
            $this->assertSame('/admin/users', admin_base_path('/users/'));
            $json = json_encode_options([
                'empty' => null,
                'enabled' => false,
                'count' => 0,
                'nested' => ['' => null, 'callback' => 'function(){return true;}'],
            ]);
            $this->assertSame('{"empty":null,"enabled":false,"count":0,"nested":{"":null,"callback":function(){return true;}}}', $json);
            $values = ['zero' => 0, 'keep' => 1];
            array_delete($values, 0);
            $this->assertSame(['keep' => 1], $values);
            $this->assertSame(0, array_first([0, false]));
            $this->assertSame(false, array_last([0, false]));
        });
    }

    public function testColumnAndRowCallbacksRetainTheirIntendedBinding()
    {
        $models = new ReflectionProperty(Column::class, 'originalGridModels');
        $previous = $models->getValue();
        try {
            $this->assertWithoutPackageDiagnostics(function () {
                $model = new User();
                $model->username = 'model-value';
                Column::setOriginalGridModels(new Collection([$model]));
                $column = (new Column('username', 'Username'))->display(function ($value) {
                    return $this->username.':'.$value;
                });
                $this->assertSame([['username' => 'model-value:raw-value']], $column->fill([['username' => 'raw-value']]));
                $row = new Row(1, ['username' => 'row-value'], 7);
                $row->column('username', function ($value) {
                    return $this->getKey().':'.$value;
                });
                $this->assertSame('7:row-value', $row->column('username'));
            });
        } finally {
            $models->setValue(null, $previous);
        }
    }

    public function testStaticModelBoundDisplayCallbackIsRejectedBeforeBinding()
    {
        $this->assertWithoutPackageDiagnostics(function () {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Grid display callbacks must be non-static');
            (new Column('username', 'Username'))->display(static fn ($value) => $value);
        });
    }

    public function testWhereFilterCallbackRetainsInputBinding()
    {
        $this->assertWithoutPackageDiagnostics(function () {
            $filter = new Where(function ($query) {
                $query->where('username', $this->input);
            }, 'Lookup', 'php85_lookup');
            $condition = $filter->condition(['php85_lookup' => 'needle']);
            $query = User::query();
            $condition['where'][0]($query);
            $this->assertSame(['needle'], $query->getBindings());
            $this->assertStringContainsString('username', $query->toSql());
            $this->assertNull($filter->condition([]));
        });
    }

    public function testFieldRulesAndValueCallbacksRetainFieldAndModelBindings()
    {
        $this->assertWithoutPackageDiagnostics(function () {
            $model = new User();
            $model->username = 'bound-model';
            $form = new Form($model);
            $field = $form->text('username');
            $field->rules(function ($owningForm) use ($form) {
                \PHPUnit\Framework\Assert::assertSame($form, $owningForm);
                return $this->column() === 'username' ? 'required|min:2' : 'prohibited';
            });
            $this->assertSame(['required', 'min:2'], $field->getRules());
            $field->value('raw')->with(function ($value) {
                return $this->username.':'.$value;
            });
            view()->share('errors', new \Illuminate\Support\ViewErrorBag());
            $field->render();
            $this->assertSame('bound-model:raw', $field->value());
        });
    }

    public function testStaticUnboundFormHookAndDynamicGridMacroRemainUsable()
    {
        $macros = new ReflectionProperty(Grid::class, 'macros');
        $previous = $macros->getValue();
        try {
            $this->assertWithoutPackageDiagnostics(function () {
                $form = new class(new User()) extends Form {
                    public function runSavingHook()
                    {
                        return $this->callSaving();
                    }
                };
                $form->saving(static function (Form $form) {
                    return response('hook:'.$form->model()->getTable(), 202);
                });
                $response = $form->runSavingHook();
                $this->assertSame(202, $response->getStatusCode());
                $this->assertSame('hook:test_users', $response->getContent());
                Grid::macro('php85Table', function () {
                    return $this->model()->getTable();
                });
                $this->assertSame('test_users', (new Grid(new User()))->php85Table());
            });
        } finally {
            $macros->setValue(null, $previous);
        }
    }
}
