<?php

namespace Encore\Admin\Actions;

use Encore\Admin\Facades\Admin;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * @mixin Action
 */
trait Authorizable
{
    /**
     * @param Model $model
     *
     * @return bool
     */
    public function passesAuthorization($model = null)
    {
        if ($model instanceof Collection) {
            if ($model->isEmpty()) {
                return false;
            }
            $explicit = method_exists($this, 'authorize');
            if ($explicit && !\Encore\Admin\Security\HandlerContext::authorizeCallback($this, $model)) {
                return false;
            }
            foreach ($model as $record) {
                $allowed = $explicit
                    ? (!method_exists($record, 'actionAuthorize') || $record->actionAuthorize(\Encore\Admin\Facades\Admin::user(), get_class($this)) === true)
                    : \Encore\Admin\Security\HandlerContext::authorized($this, $record);
                if (!$allowed) {
                    return false;
                }
            }

            return true;
        }

        return \Encore\Admin\Security\HandlerContext::authorized($this, $model);
    }

    /**
     * @return mixed
     */
    public function failedAuthorization()
    {
        return $this->response()->error(__('admin.deny'))->send();
    }
}
