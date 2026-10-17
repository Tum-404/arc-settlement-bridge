<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class CreateAdminUser extends Command
{
    protected $signature = 'users:create-admin
        {email : Email address for the settlement operator}
        {--name= : Display name for the operator}
        {--password= : Password. Omit this option to enter it securely.}
        {--force : Update an existing operator with this email}';

    protected $description = 'Create a local operator account for the settlement dashboard and log viewer';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $name = (string) ($this->option('name') ?: Str::before($email, '@'));
        $password = (string) ($this->option('password') ?: $this->secret('Password (at least 12 characters)'));

        $validator = Validator::make([
            'email' => $email,
            'name' => $name,
            'password' => $password,
        ], [
            'email' => ['required', 'email'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:12'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();
        if ($user !== null && ! $this->option('force')) {
            $this->error('An operator with this email already exists. Re-run with --force to update it.');

            return self::FAILURE;
        }

        $user ??= new User;
        $user->fill([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);
        $user->save();

        $this->info("Operator account ready for {$user->email}.");

        return self::SUCCESS;
    }
}
