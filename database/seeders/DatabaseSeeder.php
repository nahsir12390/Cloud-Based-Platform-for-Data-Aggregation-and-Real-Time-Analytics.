<?php

namespace Database\Seeders;

use App\Models\User;
use App\Rules\PasswordInput;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('OWNER_EMAIL');
        $password = env('OWNER_PASSWORD');
        if (! $email || ! $password || Validator::make(['password' => $password], ['password' => ['required', 'string', 'min:8', 'max:72', new PasswordInput]])->fails()) {
            $this->command?->warn('Set OWNER_EMAIL and OWNER_PASSWORD (8+ characters) to create the owner. No default credentials are installed.');

            return;
        }
        User::firstOrCreate(['email' => $email], ['name' => env('OWNER_NAME', 'Business Owner'), 'password' => Hash::make($password), 'role' => 'owner']);
    }
}
