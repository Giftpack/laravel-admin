<?php

namespace Encore\Admin\Security;

use Illuminate\Contracts\Validation\Validator;

/** Fixed guidance derived only from failed rule names; never render validator messages or submitted values. */
class SafeValidation
{
    public static function errors(Validator $validator, array $fields): array
    {
        $errors = [];
        foreach ($validator->failed() as $field => $rules) {
            if (!in_array($field, $fields, true)) {
                continue;
            }
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.-]{0,127}$/D', $field)) {
                continue;
            }
            foreach ($rules as $rule => $_parameters) {
                $errors[$field][] = match ($rule) {
                    'Required', 'RequiredIf', 'RequiredWith', 'Accepted' => 'This field is required.',
                    'Email' => 'Enter a valid email address.',
                    'Integer', 'Numeric', 'Decimal' => 'Enter a valid number.',
                    'Date', 'DateFormat' => 'Enter a valid date.',
                    'Unique' => 'This value is already in use.',
                    'Exists', 'In' => 'Select a valid option.',
                    'File', 'Image', 'Mimes', 'Mimetypes', 'Extensions' => 'Choose a file of an allowed type.',
                    'Min', 'Max', 'Between', 'Size' => 'Use a value within the allowed size or length.',
                    'Confirmed', 'Same' => 'The confirmation must match.',
                    default => 'Check this field and try again.',
                };
            }
        }

        return $errors;
    }
}
