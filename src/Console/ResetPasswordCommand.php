<?php

namespace Encore\Admin\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class ResetPasswordCommand extends Command
{
    protected $signature = 'admin:reset-password';
    protected $description = 'Reset password for a specific admin user';

    public function handle()
    {
        if (!$this->input->isInteractive()) {
            $this->error('This command requires interactive input; passwords must be entered at the secret prompt.');
            return self::INVALID;
        }
        try {
            $userClass = config('admin.database.users_model');
            $username = $this->ask('Please enter a username who needs to reset his password');
            $user = is_string($username) && trim($username) !== ''
                ? $userClass::query()->where('username', $username)->first() : null;
            if (!$user) {
                $this->error('The requested admin user does not exist.');
                return self::INVALID;
            }
            $password = $this->secret('Please enter a password', false);
            $confirmation = $this->secret('Please confirm the password', false);
            if (!is_string($password) || trim($password) === '' || $password !== $confirmation) {
                $this->error('A nonempty matching password and confirmation are required.');
                return self::INVALID;
            }
            $user->password = Hash::make($password);
            $user->save();
        } catch (\Throwable $exception) {
            $this->error('Unable to reset the admin password.');
            return self::FAILURE;
        }
        $this->info('User password reset successfully.');
        return self::SUCCESS;
    }
}
