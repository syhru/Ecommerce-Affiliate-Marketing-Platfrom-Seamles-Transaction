<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class FixAdminPassword extends Command
{
    protected $signature = 'admin:fix-password';

    protected $description = 'Disabled legacy credential reset; use the normal password reset flow';

    public function handle(): int
    {
        $this->error('This legacy command is disabled. Use the normal password reset flow. New accounts require superadmin:provision.');
        return self::FAILURE;
    }
}
