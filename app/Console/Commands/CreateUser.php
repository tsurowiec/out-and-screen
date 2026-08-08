<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;

/**
 * Public registration is disabled, so accounts are created here.
 */
class CreateUser extends Command
{
    protected $signature = 'screen-time:user
        {name : The person\'s name}
        {email}
        {--role=parent : parent (full access) or child (read only)}
        {--password= : Skips the prompt; handy for scripting}';

    protected $description = 'Create a login for this app (registration is disabled)';

    public function handle(): int
    {
        $role = UserRole::tryFrom($this->option('role'));

        if ($role === null) {
            $this->error('Role must be one of: '.implode(', ', array_column(UserRole::cases(), 'value')));

            return self::FAILURE;
        }

        $secret = $this->option('password') ?: password('Password', required: true);

        $validator = Validator::make([
            'name' => $this->argument('name'),
            'email' => $this->argument('email'),
            'password' => $secret,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            ...$validator->validated(),
            'role' => $role,
        ]);

        // There is no mail set up on a private app, so skip verification.
        $user->markEmailAsVerified();

        $this->info("Created {$user->email} as a {$role->value}.");

        return self::SUCCESS;
    }
}
