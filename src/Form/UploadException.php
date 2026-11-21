<?php

namespace Encore\Admin\Form;

/** Safe public response; adapter details belong to the application's own diagnostics. */
class UploadException extends \RuntimeException
{
    public function render($request)
    {
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['status' => false, 'message' => 'Upload storage is unavailable. Please retry.'], 503);
        }

        return response('Upload storage is unavailable. Please retry.', 503);
    }
}
