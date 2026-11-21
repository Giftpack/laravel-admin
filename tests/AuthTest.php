<?php

class AuthTest extends TestCase
{
    public function testLoginPage()
    {
        $this->visit('admin/auth/login')
            ->see('login');
    }

    public function testVisitWithoutLogin()
    {
        $this->visit('admin')
            ->dontSeeIsAuthenticated('admin')
            ->seePageIs('admin/auth/login');
    }

    public function testLogin()
    {
        $credentials = ['username' => 'admin', 'password' => 'admin'];

        $this->visit('admin/auth/login')
            ->see('login')
            ->submitForm('Login', $credentials)
            ->see('dashboard')
            ->seeCredentials($credentials, 'admin')
            ->seeIsAuthenticated('admin')
            ->seePageIs('admin')
            ->see('Dashboard')
            ->see('Description...')

            ->see('Environment')
            ->see('PHP version')
            ->see('Laravel version')

            ->see('Available extensions')
            ->seeLink('laravel-admin-ext/helpers', 'https://github.com/laravel-admin-extensions/helpers')
            ->seeLink('laravel-admin-ext/backup', 'https://github.com/laravel-admin-extensions/backup')
            ->seeLink('laravel-admin-ext/media-manager', 'https://github.com/laravel-admin-extensions/media-manager')

            ->see('Dependencies')
            ->see('php')
//            ->see('>=7.0.0')
            ->see('laravel/framework');

        $this
            ->see('<span>Admin</span>')
            ->see('<span>Users</span>')
            ->see('<span>Roles</span>')
            ->see('<span>Permission</span>')
            ->see('<span>Operation log</span>')
            ->see('<span>Menu</span>');
    }

    public function testFailedLoginDoesNotFlashPassword()
    {
        $this->call('POST', 'admin/auth/login', ['username'=>'admin', 'password'=>'wrong-secret']);
        $this->assertNull(session()->getOldInput('password'));
        $this->assertSame('admin', session()->getOldInput('username'));
        $this->assertTrue(Admin::guard()->guest());
    }

    public function testReadOnlyLogoutDoesNotMutateAuthentication()
    {
        $this->actingAs(Encore\Admin\Auth\Database\Administrator::first(), 'admin');
        foreach (['GET', 'HEAD'] as $method) {
            $this->call($method, 'admin/auth/logout');
            $this->assertTrue(Admin::guard()->check());
        }
        // Protect direct/custom controller routing as well as the bundled routes.
        $controller = new Encore\Admin\Controllers\AuthController;
        foreach (['PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            $controller->getLogout(Illuminate\Http\Request::create('/admin/auth/logout', $method));
            $this->assertTrue(Admin::guard()->check());
        }
    }

    public function testProfilePasswordsAreBlankAndConfirmed()
    {
        $user = Encore\Admin\Auth\Database\Administrator::first();
        $controller = new class extends Encore\Admin\Controllers\AuthController {
            public function profileFixture() { return $this->settingForm(); }
        };
        $form = $controller->profileFixture();
        $password = $form->fields()->first(fn ($field) => $field->column() === 'password');
        $password->fill($user->toArray() + ['password'=>$user->password]);
        $this->assertSame('', $password->value());
        $this->assertTrue($password->getValidator(['password'=>null])->passes());
        $this->assertFalse($password->getValidator(['password'=>'new', 'password_confirmation'=>'different'])->passes());
        // Isolate the identity saving contract without claiming full form persistence.
        $form->model()->setRawAttributes($user->getAttributes(), true);
        $saving = new ReflectionMethod($form, 'callSaving');
        $form->password = null;
        $saving->invoke($form);
        $this->assertSame($user->getAuthPassword(), $form->password);
        $form->password = 'new-secret';
        $saving->invoke($form);
        $this->assertTrue(Illuminate\Support\Facades\Hash::check('new-secret', $form->password));
    }

    public function testScopedGuardAndCookieConfigurationRestoreHostContext()
    {
        $auth = app('auth');
        $auth->shouldUse('web');
        $resolver = fn () => 'host-resolver';
        $auth->resolveUsersUsing($resolver);
        $request = Illuminate\Http\Request::create('/admin/auth/login');
        (new Encore\Admin\Middleware\Authenticate)->handle($request, function () use ($auth) {
            $this->assertSame('admin', $auth->getDefaultDriver());
            return response('ok');
        });
        $this->assertSame('web', $auth->getDefaultDriver());
        $this->assertSame($resolver, $auth->userResolver());
        config(['session.path'=>'/host', 'session.domain'=>'host.example.test']);
        $middleware = new Encore\Admin\Middleware\Session;
        $middleware->handle($request, function () {
            $this->assertSame('/host', config('session.path'));
            $this->assertSame('host.example.test', config('session.domain'));
            return response('ok');
        });
        config(['admin.session'=>['path'=>'/control', 'domain'=>'admin.example.test']]);
        try {
            $middleware->handle($request, function () {
                $this->assertSame('/control', config('session.path'));
                $this->assertSame('admin.example.test', config('session.domain'));
                throw new RuntimeException('fixture');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('fixture', $exception->getMessage());
        }
        $this->assertSame('/host', config('session.path'));
        $this->assertSame('host.example.test', config('session.domain'));
    }

    public function testNotificationsRenderForPhpAndJsonSessionPayloads()
    {
        foreach (['php', 'json'] as $serialization) {
            $bag = new Illuminate\Support\MessageBag(['message'=>'saved', 'type'=>'success']);
            session()->put('toastr', $serialization === 'php' ? $bag : json_decode(json_encode($bag), true));
            $this->assertStringContainsString("toastr.success('saved'", view('admin::partials.toastr')->render());
            $this->assertFalse(session()->has('toastr'));
            session()->put('success', $serialization === 'php' ? new Illuminate\Support\MessageBag(['title'=>'Saved', 'message'=>'done']) : ['title'=>['Saved'], 'message'=>['done']]);
            $this->assertStringContainsString('done', view('admin::partials.alerts')->render());
        }
    }

    public function testLogout()
    {
        $this->call('POST', 'admin/auth/logout');
        $this->visit('admin')
            ->seePageIs('admin/auth/login')
            ->dontSeeIsAuthenticated('admin');
    }
}
