<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Rules\PasswordInput;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateOwner extends Command
{
    protected $signature = 'branchflow:owner {--email=} {--name=}';

    protected $description = 'Create the initial business owner with a securely prompted password';

    public function handle(): int
    {
        $name = $this->option('name') ?? $this->ask('Owner name');
        $email = $this->option('email') ?? $this->ask('Owner email');
        $password = $this->secret('Password (at least 8 characters)');
        $v = Validator::make(compact('name', 'email', 'password'), ['name' => 'required|string|max:100', 'email' => 'required|email|unique:users', 'password' => ['required', 'string', 'min:8', 'max:72', new PasswordInput]]);
        if ($v->fails()) {
            foreach ($v->errors()->all() as $e) {
                $this->error($e);
            }

            return self::FAILURE;
        }
        User::create(['name' => $name, 'email' => $email, 'password' => Hash::make($password), 'role' => 'owner']);
        $this->info('Owner created. You can now sign in.');

        return self::SUCCESS;
    }
}
