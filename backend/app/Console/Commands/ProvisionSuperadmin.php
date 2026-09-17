<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class ProvisionSuperadmin extends Command
{
    protected $signature = 'superadmin:provision {email} {--name= : Display name for the new account}';

    protected $description = 'Internally create a superadmin using a hidden password prompt; email verification is still required';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Provisioning requires an interactive terminal with hidden password input.');
            return self::FAILURE;
        }

        $email = strtolower(trim((string) $this->argument('email')));
        $name = trim((string) $this->option('name'));
        $validator = Validator::make(['email' => $email, 'name' => $name], [
            'email' => ['required', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
        ]);
        if ($validator->fails()) {
            $this->error('A valid email and --name are required.');
            return self::FAILURE;
        }

        $existingUser = User::whereRaw('LOWER(email) = ?', [$email])->first();
        if ($existingUser) {
            if ($existingUser->isSuperadmin()) {
                $this->error('This account is already a superadmin; no account was changed.');
                return self::FAILURE;
            }

            if (! $this->confirm("Promote existing account {$existingUser->email} to superadmin and revoke all existing credentials?")) {
                $this->warn('Promotion cancelled; no account was changed.');
                return self::FAILURE;
            }

            $existingUser->role = 'superadmin';
            $existingUser->save();
            $this->info('Existing account promoted. Its current verification and active state were preserved, and existing credentials were revoked.');
            return self::SUCCESS;
        }

        // Never fall back to visible input when the terminal cannot hide secrets.
        $password = $this->secret('Password (minimum 12 characters)', false);
        $confirmation = $this->secret('Confirm password', false);
        if (! is_string($password) || strlen($password) < 12 || strlen($password) > 72 || $password !== $confirmation) {
            $this->error('Passwords must match and contain 12 to 72 bytes.');
            return self::FAILURE;
        }

        $user = new User;
        $user->name = $name;
        $user->email = $email;
        $user->password = Hash::make($password);
        $user->role = 'superadmin';
        $user->is_active = true;
        $user->email_verified_at = null;
        $user->save();

        $this->info('Superadmin created. Verify the email through the normal verification flow before privileged access.');
        return self::SUCCESS;
    }
}
