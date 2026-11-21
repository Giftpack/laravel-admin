<?php

namespace Encore\Admin\Grid\Actions;

use Encore\Admin\Actions\Response;
use Encore\Admin\Actions\RowAction;
use Illuminate\Database\Eloquent\Model;
use Encore\Admin\Form;

class Delete extends RowAction
{
    /**
     * @return array|null|string
     */
    public function name()
    {
        return __('admin.delete');
    }

    /**
     * @param Model $model
     *
     * @return Response
     */
    public function handle(Model $model)
    {
        $trans = [
            'failed'    => trans('admin.delete_failed'),
            'succeeded' => trans('admin.delete_succeeded'),
        ];

        try {
            $model->getConnection()->transaction(function () use ($model) {
                if ($model->delete() === false) {
                    throw new \RuntimeException('Record deletion was cancelled.');
                }

                $userModel = config('admin.database.users_model');
                if (is_string($userModel) && $model instanceof $userModel
                    && !$this->modelUseSoftDeletes(get_class($model))) {
                    // Only the built-in administrator avatar is a known upload contract.
                    $field = (new Form($model))->image('avatar');
                    $field->setOriginal(['avatar' => $model->getRawOriginal('avatar')]);
                    $field->destroy();
                }
            });
        } catch (\Throwable $exception) {
            $response = $this->response()->error($trans['failed']);
            $response->status = false;

            return $response;
        }

        return $this->response()->success($trans['succeeded'])->refresh();
    }

    /**
     * @return void
     */
    public function dialog()
    {
        $this->question(trans('admin.delete_confirm'), '', ['confirmButtonColor' => '#d33']);
    }
}
