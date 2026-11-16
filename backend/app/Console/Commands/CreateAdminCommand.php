<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Environment-safe way to create an administrator (works in production, unlike the demo seeder).
 * The password is read interactively and never echoed or logged.
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'lexora:create-admin {--name= : Full name} {--email= : Login email}';

    protected $description = 'Create a Lexora Workspace administrator account';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Full name');
        $email = mb_strtolower(trim((string) ($this->option('email') ?: $this->ask('Email address'))));
        $password = (string) $this->secret('Password (min. 12 characters)');
        $confirmation = (string) $this->secret('Confirm password');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirmation],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'confirmed', Password::defaults()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
        $user->role = UserRole::Admin;
        $user->email_verified_at = now();
        $user->save();

        $this->info("Administrator {$user->email} created.");

        return self::SUCCESS;
    }
}
