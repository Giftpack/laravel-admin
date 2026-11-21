<?php

namespace Encore\Admin\Security;

use Encore\Admin\Facades\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/** Server-issued scope; never an operation permission in its own right. */
class HandlerContext
{
    public static function issue(string $kind, string $class, array $scope = []): string
    {
        $user = Admin::user();
        if (!$user) {
            return '';
        }

        return Crypt::encryptString(json_encode([
            'kind' => $kind, 'class' => $class, 'scope' => self::scope($scope),
            'actor' => (string) $user->getAuthIdentifier(),
            'guard' => config('admin.auth.guard'),
            'host' => request()->getHost(), 'prefix' => config('admin.route.prefix'),
            'expires' => time() + 3600,
        ], JSON_THROW_ON_ERROR));
    }

    private static function scope(array $scope): array
    {
        if (isset($scope['args'])) {
            $scope['args'] = array_map(static fn ($value) => (string) (is_bool($value) ? (int) $value : $value), $scope['args']);
        }

        return $scope;
    }

    public static function validate(Request $request, string $kind, string $class, array $scope = []): void
    {
        abort_unless(Admin::user(), 401);
        try {
            $token = $request->input('_context');
            $context = is_string($token) ? json_decode(Crypt::decryptString($token), true, 32, JSON_THROW_ON_ERROR) : null;
        } catch (\Throwable $exception) {
            $context = null;
        }
        abort_unless(is_array($context)
            && ($context['kind'] ?? null) === $kind
            && ($context['class'] ?? null) === $class
            && ($context['scope'] ?? null) === self::scope($scope)
            && ($context['actor'] ?? null) === (string) Admin::user()->getAuthIdentifier()
            && ($context['guard'] ?? null) === config('admin.auth.guard')
            && ($context['host'] ?? null) === $request->getHost()
            && ($context['prefix'] ?? null) === config('admin.route.prefix')
            && ($context['expires'] ?? 0) >= time(), 403, 'Invalid handler context.');
    }

    /** Preserve existing public/protected Action callbacks; private callbacks stay unsupported. */
    public static function authorizeCallback(object $handler, $subject = null): bool
    {
        $callback = new \ReflectionMethod($handler, 'authorize');
        if (!$callback->isPublic() && !($handler instanceof \Encore\Admin\Actions\Action && $callback->isProtected())) {
            return false;
        }

        return $callback->invoke($handler, Admin::user(), $subject) === true;
    }

    /** An explicit ability is useful for handlers without an authorize callback. */
    public static function authorized(object $handler, $subject = null): bool
    {
        $user = Admin::user();
        if (!$user) {
            return false;
        }
        if (method_exists($handler, 'authorize')) {
            return self::authorizeCallback($handler, $subject);
        }
        if (is_object($subject) && method_exists($subject, 'actionAuthorize')) {
            return $subject->actionAuthorize($user, get_class($handler)) === true;
        }
        if ($user->isAdministrator()) {
            return true;
        }
        $ability = config('admin.handler_abilities', [])[get_class($handler)] ?? null;

        return is_string($ability) && $ability !== '' && $user->can($ability);
    }
}
