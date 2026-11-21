<?php

namespace Encore\Admin\Controllers;

use Encore\Admin\Actions\Action;
use Encore\Admin\Actions\BatchAction;
use Encore\Admin\Actions\GridAction;
use Encore\Admin\Actions\Response;
use Encore\Admin\Actions\RowAction;
use Encore\Admin\Security\HandlerContext;
use Encore\Admin\Security\InputRedactor;
use Encore\Admin\Widgets\Form;
use Exception;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;

class HandleController extends Controller
{
    /**
     * @param Request $request
     *
     * @return $this|mixed
     */
    public function handleForm(Request $request)
    {
        $form = $this->resolveForm($request);
        abort_unless(HandlerContext::authorized($form), 403);

        if ($errors = $form->validate($request)) {
            return back()->withInput(InputRedactor::redact($request->except(['_context', '_token'])))->withErrors($errors);
        }

        return $form->sanitize()->handle($request);
    }

    /**
     * @param Request $request
     *
     * @throws Exception
     *
     * @return Form
     */
    protected function resolveForm(Request $request)
    {
        $formClass = $request->input('_form_');
        abort_unless(is_string($formClass) && is_subclass_of($formClass, Form::class), 403);
        HandlerContext::validate($request, 'form', $formClass);
        abort_unless(method_exists($formClass, 'handle'), 403);
        $form = app($formClass);

        return $form;
    }

    /**
     * @param Request $request
     *
     * @return $this|\Illuminate\Http\JsonResponse
     */
    public function handleAction(Request $request)
    {
        $action = $this->resolveActionInstance($request);

        $model = null;
        $arguments = [];

        if ($action instanceof GridAction) {
            $model = $action->retrieveModel($request);
            // A retrieval sentinel must never become an authorized subject or handler argument.
            if ($action instanceof BatchAction) {
                abort_unless($model instanceof EloquentCollection && $model->isNotEmpty(), 404);
            } elseif ($action instanceof RowAction) {
                abort_unless($model instanceof Model, 404);
            } else {
                abort_unless($model instanceof Model || ($model instanceof EloquentCollection && $model->isNotEmpty()), 404);
            }
            $arguments[] = $model;
        }

        if (!$action->passesAuthorization($model)) {
            abort(403, trans('admin.deny'));
        }

        if ($action instanceof RowAction) {
            $action->setRow($model);
        }

        try {
            $response = $action->validate($request)->handle(
                ...$this->resolveActionArgs($request, ...$arguments)
            );
        } catch (\Illuminate\Validation\ValidationException $exception) {
            // Only declared interactor fields carry trusted guidance; arbitrary handler
            // ValidationException messages retain the same generic failure contract.
            $failure = new Response();
            $failure->status = false;
            $response = $failure->toastr()->error(__('admin.failed'))->send();
            if ($action->hasSafeValidationErrors()) {
                $response->setData($response->getData(true) + ['errors' => $action->safeValidationErrors()]);
            }

            return $response;
        } catch (\Throwable $exception) {
            $failure = new Response();
            $failure->status = false;

            return $failure->toastr()->error(__('admin.failed'))->send();
        }

        if ($response instanceof Response) {
            return $response->send();
        }
    }

    /**
     * @param Request $request
     *
     * @throws Exception
     *
     * @return Action
     */
    protected function resolveActionInstance(Request $request)
    {
        $encoded = $request->input('_action');
        abort_unless(is_string($encoded), 403);
        $actionClass = str_replace('_', '\\', $encoded);
        abort_unless(is_subclass_of($actionClass, Action::class) && method_exists($actionClass, 'handle'), 403);
        $scope = [];
        if (is_subclass_of($actionClass, GridAction::class)) {
            $model = $request->input('_model');
            abort_unless(is_string($model) && is_subclass_of(str_replace('_', '\\', $model), Model::class), 403);
            $scope['model'] = $model;
            $keys = $request->input('_key');
            abort_unless(is_string($keys) || is_int($keys) || (is_array($keys) && $keys !== []), 403);
            foreach (is_array($keys) ? $keys : explode(',', (string) $keys) as $key) {
                abort_unless((is_string($key) || is_int($key)) && trim((string) $key) !== '', 403);
            }
            if (is_subclass_of($actionClass, RowAction::class)) {
                abort_unless(!is_array($keys), 403);
                $scope['key'] = (string) $keys;
            }
        }
        HandlerContext::validate($request, 'action', $actionClass, $scope);
        $action = app($actionClass);

        return $action;
    }

    /**
     * @param Request               $request
     * @param Model|Collection|null $model
     *
     * @return array
     */
    protected function resolveActionArgs(Request $request, $model = null)
    {
        $args = [$request];

        if ($model !== null) {
            array_unshift($args, $model);
        }

        return $args;
    }

    /**
     * @param Request $request
     *
     * @return mixed|string|string[]
     */
    public function handleSelectable(Request $request)
    {
        $encoded = $request->input('selectable');
        $args = $request->input('args', []);
        abort_unless(is_string($encoded) && is_array($args), 403);
        $class = str_replace('_', '\\', $encoded);
        foreach ($args as $arg) {
            abort_unless(is_scalar($arg) || $arg === null, 403);
        }
        abort_unless(is_subclass_of($class, \Encore\Admin\Grid\Selectable::class), 403);
        // Query-string arguments are strings; normalize producer values to match transport.
        HandlerContext::validate($request, 'selectable', $class, ['args' => array_values($args)]);
        $selectable = new $class(...array_values($args));
        abort_unless(HandlerContext::authorized($selectable), 403);

        return $selectable->render();
    }

    /**
     * @param Request $request
     *
     * @return mixed|string|string[]
     */
    public function handleRenderable(Request $request)
    {
        $encoded = $request->input('renderable');
        $key = $request->input('key');
        abort_unless(is_string($encoded) && (is_string($key) || is_int($key)), 403);
        $class = str_replace('_', '\\', $encoded);
        abort_unless(is_subclass_of($class, Renderable::class), 403);
        HandlerContext::validate($request, 'renderable', $class, ['key' => (string) $key]);
        $renderable = new $class();
        abort_unless(HandlerContext::authorized($renderable, $key), 403);

        return $renderable->render($key);
    }
}
