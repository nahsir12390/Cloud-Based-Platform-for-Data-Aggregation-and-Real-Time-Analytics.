<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RenderDeploymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_creates_initial_owner_and_preserves_it_on_restart(): void
    {
        config(['deployment.owner_name' => 'Initial Owner', 'deployment.owner_email' => 'initial@example.test', 'deployment.owner_password' => 'Deployment-Owner-123!']);
        $this->artisan('branchflow:deploy', ['--attempts' => 1])->assertSuccessful();
        $owner = User::where('email', 'initial@example.test')->firstOrFail();
        $this->assertSame('owner', $owner->role);
        $this->assertTrue(Hash::check('Deployment-Owner-123!', $owner->password));
        $hash = $owner->password;
        config(['deployment.owner_email' => 'replacement@example.test', 'deployment.owner_password' => 'Changed-Environment-123!']);
        $this->artisan('branchflow:deploy', ['--attempts' => 1])->assertSuccessful();
        $this->assertSame($hash, $owner->fresh()->password);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_bootstrap_without_credentials_fails_on_an_empty_database(): void
    {
        config(['deployment.owner_email' => null, 'deployment.owner_password' => null]);
        $this->artisan('branchflow:deploy', ['--attempts' => 1])->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_bootstrap_never_promotes_an_existing_staff_email(): void
    {
        $staff = User::factory()->create(['role' => 'staff', 'email' => 'existing@example.test']);
        $hash = $staff->password;
        config(['deployment.owner_name' => 'Owner', 'deployment.owner_email' => 'existing@example.test', 'deployment.owner_password' => 'Deployment-Owner-123!']);
        $this->artisan('branchflow:deploy', ['--attempts' => 1])->assertFailed();
        $this->assertSame('staff', $staff->fresh()->role);
        $this->assertSame($hash, $staff->fresh()->password);
    }

    public function test_bootstrap_rejects_an_invalid_application_key(): void
    {
        config(['app.key' => 'invalid']);
        $this->app->forgetInstance('encrypter');
        $this->artisan('branchflow:deploy', ['--attempts' => 1])->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_production_rejects_sqlite_instead_of_silently_losing_data(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $original = config('database.default');
        try {
            config(['database.default' => 'sqlite']);
            $this->artisan('branchflow:deploy', ['--attempts' => 1])->assertFailed();
        } finally {
            config(['database.default' => $original]);
        }
    }

    public function test_production_allows_explicit_temporary_sqlite_demo(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.debug' => false, 'deployment.ephemeral_sqlite' => true,
            'deployment.owner_email' => 'owner@example.test',
            'deployment.owner_password' => 'Deployment-Owner-123!',
        ]);
        $this->artisan('branchflow:deploy', ['--attempts' => 1])->assertSuccessful();
        $this->assertDatabaseHas('users', ['email' => 'owner@example.test', 'role' => 'owner']);
    }

    public function test_production_rejects_debug_mode(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $original = config('database.default');
        try {
            config(['database.default' => 'pgsql', 'app.debug' => true]);
            $this->artisan('branchflow:deploy', ['--attempts' => 1])->assertFailed();
        } finally {
            config(['database.default' => $original]);
        }
    }

    public function test_readiness_is_public_and_does_not_start_a_database_session(): void
    {
        $this->getJson('/api/health/ready')->assertOk()->assertExactJson(['status' => 'ok'])->assertHeaderMissing('Set-Cookie');
    }

    public function test_database_failure_returns_a_generic_readiness_response(): void
    {
        DB::shouldReceive('select')->once()->with('SELECT 1')->andThrow(new \RuntimeException('postgres://private-credentials'));
        $this->getJson('/api/health/ready')->assertServiceUnavailable()->assertExactJson(['status' => 'unavailable'])->assertDontSee('private-credentials');
    }

    public function test_trusted_render_headers_enable_https_without_trusting_forwarded_host(): void
    {
        config(['deployment.trust_proxies' => true]);
        Route::get('/test-proxy', fn (Request $request) => response()->json(['secure' => $request->isSecure(), 'ip' => $request->ip(), 'host' => $request->getHost()]));
        $this->withHeaders(['Host' => 'branchflow.onrender.com', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443', 'X-Forwarded-For' => '203.0.113.10', 'X-Forwarded-Host' => 'attacker.example'])->getJson('http://branchflow.onrender.com/test-proxy')->assertOk()->assertExactJson(['secure' => true, 'ip' => '203.0.113.10', 'host' => 'branchflow.onrender.com']);
    }

    public function test_proxy_trust_is_not_enabled_by_default_locally(): void
    {
        config(['deployment.trust_proxies' => false]);
        Route::get('/test-proxy', fn (Request $request) => response()->json(['secure' => $request->isSecure()]));
        $this->withHeaders(['X-Forwarded-Proto' => 'https'])->getJson('/test-proxy')->assertOk()->assertExactJson(['secure' => false]);
    }

    public function test_production_demo_seeding_requires_an_explicit_opt_in(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['deployment.seed_demo' => false]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Demo data is disabled in production.');
        $this->artisan('db:seed', ['--class' => DemoSeeder::class, '--force' => true])->run();
    }

    public function test_production_demo_seeding_uses_the_configured_staff_password(): void
    {
        User::factory()->create(['role' => 'owner']);
        $this->app->detectEnvironment(fn () => 'production');
        config(['deployment.seed_demo' => true, 'deployment.demo_staff_password' => 'Demo-Staff-Deployment-123!']);

        $this->artisan('db:seed', ['--class' => DemoSeeder::class, '--force' => true])->run();

        $this->assertDatabaseCount('sales', 726);
        $staff = User::where('role', 'staff')->get();
        $this->assertCount(3, $staff);
        foreach ($staff as $user) {
            $this->assertTrue(Hash::check('Demo-Staff-Deployment-123!', $user->password));
        }
    }

    public function test_production_demo_seeding_rejects_missing_staff_credentials(): void
    {
        User::factory()->create(['role' => 'owner']);
        $this->app->detectEnvironment(fn () => 'production');
        config(['deployment.seed_demo' => true, 'deployment.demo_staff_password' => null]);
        $this->expectException(ValidationException::class);
        $this->artisan('db:seed', ['--class' => DemoSeeder::class, '--force' => true])->run();
    }

    public function test_production_demo_deployment_accepts_password_for_owner_and_staff(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.debug' => false, 'deployment.ephemeral_sqlite' => true,
            'deployment.owner_email' => 'demo-owner@example.test', 'deployment.owner_password' => 'password',
            'deployment.seed_demo' => true, 'deployment.demo_staff_password' => 'password',
        ]);
        $this->artisan('branchflow:deploy', ['--attempts' => 1])->assertSuccessful();
        $this->assertDatabaseCount('sales', 726);
        $this->app->detectEnvironment(fn () => 'testing');
        $this->post('/login', ['email' => 'demo-owner@example.test', 'password' => 'password'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs(User::where('email', 'demo-owner@example.test')->firstOrFail());
        foreach (User::where('role', 'staff')->get() as $staff) {
            $this->assertTrue(Hash::check('password', $staff->password));
        }
    }

    public function test_deployment_identifies_the_invalid_demo_staff_setting(): void
    {
        User::factory()->create(['role' => 'owner']);
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.debug' => false, 'deployment.ephemeral_sqlite' => true,
            'deployment.seed_demo' => true, 'deployment.demo_staff_password' => 'short',
        ]);
        $this->artisan('branchflow:deploy', ['--attempts' => 1])
            ->expectsOutputToContain('Set DEPLOY_DEMO_STAFF_PASSWORD')
            ->assertFailed();
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_deployment_restarts_do_not_extend_the_seeded_dataset_each_day(): void
    {
        $this->freezeTime();
        User::factory()->create(['role' => 'owner']);
        config(['deployment.seed_demo' => true]);
        $this->artisan('branchflow:deploy', ['--attempts' => 1])->assertSuccessful();
        $this->assertDatabaseCount('sales', 726);

        $this->travel(1)->days();
        $this->artisan('branchflow:deploy', ['--attempts' => 1])->assertSuccessful();
        $this->assertDatabaseCount('sales', 726);
        $this->travelBack();
    }
}
