<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class FixAdminPassword extends Command
{
    protected $signature = 'admin:fix-password';
    protected $description = 'Set admin@tdr.test password to admin123';

    public function handle(): void
    {
        $user = User::where('email', 'admin@tdr.test')->first();

        if (! $user) {
            $this->error('Admin user not found.');
            return;
        }

        $user->password_hash = Hash::make('admin123');
        $user->save();

        $this->info("Password updated for {$user->email}");
    }
}
