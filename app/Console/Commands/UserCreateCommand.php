<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * The only way an account comes to exist: registration is switched off, and
 * the login exists solely to put the visitor stats behind a password.
 */
class UserCreateCommand extends Command
{
    protected $signature = 'user:create {email? : Address to log in with}';

    protected $description = 'Create a maintainer account for the stats pages';

    public function handle(): int
    {
        $email = $this->argument('email') ?? text(
            label: 'Email',
            required: true,
            validate: fn (string $value) => $this->emailError($value),
        );

        if (($error = $this->emailError($email)) !== null) {
            $this->error($error);

            return self::FAILURE;
        }

        $name = text(label: 'Name', required: true);

        $password = password(
            label: 'Password',
            required: true,
            validate: fn (string $value) => $this->passwordError($value),
        );

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();

        $this->components->info("Created {$user->email}. Log in at ".route('login').'.');

        return self::SUCCESS;
    }

    protected function emailError(string $email): ?string
    {
        return $this->firstError(['email' => $email], ['email' => ['required', 'email', 'unique:users,email']]);
    }

    protected function passwordError(string $password): ?string
    {
        return $this->firstError(['password' => $password], ['password' => ['required', 'string', Password::default()]]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, array<int, mixed>>  $rules
     */
    protected function firstError(array $data, array $rules): ?string
    {
        $validator = Validator::make($data, $rules);

        return $validator->fails() ? $validator->errors()->first() : null;
    }
}
