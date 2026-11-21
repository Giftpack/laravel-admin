<?php

namespace Encore\Admin\Security;

class InputRedactor
{
    public const MASK = '[REDACTED]';

    public static function redact(array $input): array
    {
        $patterns = array_merge([
            '*password*', '*passwd*', '*pwd*', '*token*', '*secret*',
            '*authorization*', '*cookie*', '*api_key*', '*apikey*', '*private_key*',
            '*client_key*', '*credential*', '_context',
        ], config('admin.operation_log.sensitive_keys', []));
        foreach ($input as $key => $value) {
            $normalized = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', (string) $key));
            $normalized = preg_replace('/[^a-z0-9_]/', '_', $normalized);
            $sensitive = false;
            foreach ($patterns as $pattern) {
                $pattern = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', (string) $pattern));
                $pattern = preg_replace('/[^a-z0-9_*]/', '_', $pattern);
                if (\Illuminate\Support\Str::is($pattern, $normalized)) {
                    $sensitive = true;
                    break;
                }
            }
            $input[$key] = $sensitive ? self::MASK : (is_array($value) ? self::redact($value) : $value);
        }

        return $input;
    }

    /** Malformed historic payloads cannot safely be displayed as unstructured text. */
    public static function json($input): string
    {
        if (is_string($input)) {
            $input = json_decode($input, true);
        }

        return json_encode(is_array($input) ? self::redact($input) : [], JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG);
    }
}
