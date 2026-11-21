<?php

namespace Encore\Admin\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateUserCommand extends Command
{
    protected $signature = 'admin:create-user';
    protected $description = 'Create an admin user';

    public function handle()
    {
        if (!$this->input->isInteractive()) {
            $this->error('This command requires interactive input; passwords must be entered at the secret prompt.');
            return self::INVALID;
        }
        try {
            $userClass = config('admin.database.users_model');
            $roleClass = config('admin.database.roles_model');
            $user = new $userClass();
            $username = $this->ask('Please enter a username to login');
            $password = $this->secret('Please enter a password to login', false);
            $name = $this->ask('Please enter a name to display');
            $validator = Validator::make(compact('username', 'password', 'name'), [
                'username' => 'required|string|max:190',
                'password' => 'required|string',
                'name' => 'required|string|max:255',
            ]);
            if ($validator->fails() || $user->newQuery()->where('username', $username)->exists()) {
                $this->error('A valid unique username, nonempty password and display name are required.');
                return self::INVALID;
            }
            $roles = $roleClass::all();
            $selected = [];
            if ($roles->isNotEmpty()) {
                $selected = $this->choice('Please choose a role for the user', $roles->pluck('name')->all(), null, 1, true);
            }
            $roleIds = $roles->filter(fn ($role) => in_array($role->name, $selected, true))->modelKeys();
            $user->getConnection()->transaction(function () use ($user, $username, $password, $name, $roleIds) {
                $user->fill(['username' => $username, 'password' => Hash::make($password), 'name' => $name]);
                $user->save();
                $user->roles()->attach($roleIds);
            });
        } catch (\Throwable $exception) {
            // SQL/validation exceptions may contain credential bindings: do not print them.
            $this->error('Unable to create the admin user.');
            return self::FAILURE;
        }
        $this->info('Admin user created successfully.');
        return self::SUCCESS;
    }
}
