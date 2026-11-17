<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use App\Rules\PasswordInput;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Sleep;
use Throwable;

class BootstrapDeployment extends Command
{
    protected $signature = 'branchflow:deploy {--attempts=30 : Maximum database connection attempts}';

    protected $description = 'Prepare a deployment database and create its initial owner without resetting existing accounts';

    public function handle(): int
    {
        $attempts = filter_var($this->option('attempts'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 60]]);
        if (! $attempts) {
            $this->error('Use between 1 and 60 database connection attempts.');

            return self::FAILURE;
        }
        if (app()->environment('production') && config('database.default') !== 'pgsql'
            && ! (config('database.default') === 'sqlite' && config('deployment.ephemeral_sqlite'))) {
            $this->error('Production requires PostgreSQL or explicit DEPLOY_EPHEMERAL_SQLITE=true for a temporary SQLite demo.');

            return self::FAILURE;
        }
        if (app()->environment('production') && config('app.debug')) {
            $this->error('Production requires APP_DEBUG=false.');

            return self::FAILURE;
        }
        if (config('database.default') === 'sqlite' && config('deployment.ephemeral_sqlite')) {
            $this->warn('Temporary SQLite demo: hosting restarts may erase all data.');
        }
        try {
            app('encrypter');
        } catch (Throwable) {
            $this->error('Set a valid, stable APP_KEY in the deployment environment.');

            return self::FAILURE;
        }
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                DB::connection()->getPdo();
                break;
            } catch (Throwable) {
                DB::purge();
                if ($attempt === $attempts) {
                    $this->error('Database connection unavailable. Check DB_URL, SSL and database availability.');

                    return self::FAILURE;
                }
                $this->warn('Waiting for database availability (attempt '.$attempt.').');
                Sleep::for(2)->seconds();
            }
        }
        if ($this->call('migrate', ['--force' => true, '--no-interaction' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }
        if (! User::where('role', 'owner')->exists()) {
            $data = ['name' => config('deployment.owner_name'), 'email' => config('deployment.owner_email'), 'password' => config('deployment.owner_password')];
            $validation = Validator::make($data, ['name' => 'required|string|max:100', 'email' => 'required|email|max:200', 'password' => ['required', 'string', 'min:8', 'max:72', new PasswordInput]]);
            if ($validation->fails()) {
                $this->error('Set OWNER_NAME, OWNER_EMAIL and a valid OWNER_PASSWORD (8+ characters) for the first deployment.');

                return self::FAILURE;
            }
            $owner = User::firstOrCreate(['email' => $data['email']], ['name' => $data['name'], 'password' => Hash::make($data['password']), 'role' => 'owner']);
            if ($owner->role !== 'owner') {
                $this->error('The bootstrap email belongs to a non-owner account. Choose a different OWNER_EMAIL.');

                return self::FAILURE;
            }
            $this->info('Initial owner created.');
        } else {
            $this->info('Existing owner preserved.');
        }
        if (config('deployment.seed_demo') && ! AuditLog::where('action', 'demo.seeded')->exists()) {
            if (app()->environment('production') && Validator::make(
                ['password' => config('deployment.demo_staff_password')],
                ['password' => ['required', 'string', 'min:8', 'max:72', new PasswordInput]],
            )->fails()) {
                $this->error('Set DEPLOY_DEMO_STAFF_PASSWORD to a valid password (8–72 characters) for the demo staff accounts.');

                return self::FAILURE;
            }
            if ($this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true, '--no-interaction' => true]) !== self::SUCCESS) {
                return self::FAILURE;
            }
        }
        $this->info('Deployment database is ready.');

        return self::SUCCESS;
    }
}
