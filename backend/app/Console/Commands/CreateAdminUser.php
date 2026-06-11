<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateAdminUser extends Command
{
    protected $signature = 'volta:create-admin
        {--email= : Admin email}
        {--password= : Admin password (min 6 characters)}
        {--name=Administrator : Display name}';

    protected $description = 'Create or update a backoffice administrator account.';

    public function handle(): int
    {
        $email = trim((string) ($this->option('email') ?: $this->ask('Admin email')));
        $password = (string) ($this->option('password') ?: $this->secret('Admin password'));
        $name = trim((string) $this->option('name'));

        $validator = Validator::make([
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ], [
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:6',
            'name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = User::query()->updateOrCreate(
            ['email' => strtolower($email)],
            [
                'name' => $name,
                'first_name' => null,
                'last_name' => null,
                'currency' => 'MDL',
                'password' => Hash::make($password),
            ]
        );

        $user->forceFill(['is_admin' => true])->save();

        $this->info("Administrator ready: {$user->email}");

        return self::SUCCESS;
    }
}
