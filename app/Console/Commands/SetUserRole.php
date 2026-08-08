<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;

class SetUserRole extends Command
{
    protected $signature = 'screen-time:role {email} {role : parent or child}';

    protected $description = 'Set whether a user can manage screen time (parent) or only view it (child)';

    public function handle(): int
    {
        $role = UserRole::tryFrom($this->argument('role'));

        if ($role === null) {
            $this->error('Role must be one of: '.implode(', ', array_column(UserRole::cases(), 'value')));

            return self::FAILURE;
        }

        $user = User::where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error("No user with email {$this->argument('email')}.");

            return self::FAILURE;
        }

        $user->update(['role' => $role]);

        $this->info("{$user->email} is now a {$role->value}.");

        return self::SUCCESS;
    }
}
