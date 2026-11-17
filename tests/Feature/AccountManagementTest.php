<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AccountManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $staff;

    protected Branch $branch;

    protected string $ownerPassword = 'Owner-Password-123!';

    protected string $staffPassword = 'Staff-Password-123!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::create(['name' => 'Wuse', 'location' => 'Abuja']);
        $this->owner = User::factory()->create(['role' => 'owner', 'password' => $this->ownerPassword]);
        $this->staff = User::factory()->create(['role' => 'staff', 'branch_id' => $this->branch->id, 'password' => $this->staffPassword]);
    }

    private function resetPayload(string $ownerPassword = 'Owner-Password-123!'): array
    {
        return ['current_password' => $ownerPassword, 'password' => 'New-Staff-Password-123!', 'password_confirmation' => 'New-Staff-Password-123!'];
    }

    public function test_active_users_can_view_account_page_and_owner_can_view_staff_editor(): void
    {
        $this->actingAs($this->owner)->get('/account')->assertOk()->assertSee('Change password');
        $this->get('/team/'.$this->staff->id.'/edit')->assertOk()->assertSee('Staff profile');
        $this->actingAs($this->staff)->get('/account')->assertOk()->assertSee('Wuse');
    }

    public function test_inactive_users_cannot_login(): void
    {
        $this->staff->is_active = false;
        $this->staff->save();
        $this->post('/login', ['email' => $this->staff->email, 'password' => $this->staffPassword])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_password_change_requires_correct_current_password(): void
    {
        $hash = $this->staff->password;
        $this->actingAs($this->staff)->put('/account/password', $this->resetPayload('incorrect'))->assertSessionHasErrors('current_password');
        $this->assertSame($hash, $this->staff->fresh()->password);
    }

    public function test_password_change_preserves_current_session_and_invalidates_older_versions(): void
    {
        $oldVersion = $this->staff->auth_version;
        $oldToken = $this->staff->remember_token;
        $payload = array_merge($this->resetPayload($this->staffPassword), ['password' => 'demo1234', 'password_confirmation' => 'demo1234']);
        $this->actingAs($this->staff)->put('/account/password', $payload)->assertRedirect('/account');
        $updated = $this->staff->fresh();
        $this->assertTrue(Hash::check($payload['password'], $updated->password));
        $this->assertSame($oldVersion + 1, $updated->auth_version);
        $this->assertNotSame($oldToken, $updated->remember_token);
        $this->get('/account')->assertOk();
        $this->withSession(['auth_version' => $oldVersion])->get('/account')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.password_changed']);
        $this->assertStringNotContainsString($payload['password'], AuditLog::firstOrFail()->description);
    }

    public function test_password_confirmation_and_minimum_length_are_enforced(): void
    {
        $this->actingAs($this->staff)->put('/account/password', ['current_password' => $this->staffPassword, 'password' => 'short', 'password_confirmation' => 'different'])->assertSessionHasErrors('password');
    }

    public function test_same_password_is_rejected(): void
    {
        $this->actingAs($this->staff)->put('/account/password', ['current_password' => $this->staffPassword, 'password' => $this->staffPassword, 'password_confirmation' => $this->staffPassword])->assertSessionHasErrors('password');
    }

    public function test_owner_can_deactivate_reactivate_and_old_sessions_stay_invalid(): void
    {
        $oldVersion = $this->staff->auth_version;
        $this->actingAs($this->owner)->post('/team/'.$this->staff->id.'/status', ['active' => 0])->assertRedirect('/team');
        $this->assertFalse($this->staff->fresh()->is_active);
        $this->actingAs($this->staff)->get('/dashboard')->assertRedirect('/login');
        $this->actingAs($this->owner)->post('/team/'.$this->staff->id.'/status', ['active' => 1])->assertRedirect('/team');
        $this->assertTrue($this->staff->fresh()->is_active);
        $this->actingAs($this->staff)->withSession(['auth_version' => $oldVersion])->get('/dashboard')->assertRedirect('/login');
        $this->post('/login', ['email' => $this->staff->email, 'password' => $this->staffPassword])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();
    }

    public function test_staff_cannot_edit_reset_or_deactivate_other_accounts(): void
    {
        $other = User::factory()->create(['role' => 'staff', 'branch_id' => $this->branch->id]);
        $this->actingAs($this->staff)->get('/team/'.$other->id.'/edit')->assertForbidden();
        $this->patch('/team/'.$other->id, ['name' => 'Hijacked'])->assertForbidden();
        $this->post('/team/'.$other->id.'/status', ['active' => 0])->assertForbidden();
        $this->put('/team/'.$other->id.'/password', $this->resetPayload($this->staffPassword))->assertForbidden();
        $this->assertTrue($other->fresh()->is_active);
    }

    public function test_owner_accounts_cannot_be_managed_through_staff_endpoints(): void
    {
        $anotherOwner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner)->get('/team/'.$anotherOwner->id.'/edit')->assertForbidden();
        $this->post('/team/'.$this->owner->id.'/status', ['active' => 0])->assertForbidden();
        $this->put('/team/'.$anotherOwner->id.'/password', $this->resetPayload())->assertForbidden();
        $this->assertTrue($this->owner->fresh()->is_active);
    }

    public function test_owner_reset_requires_owner_password_and_keeps_inactive_status(): void
    {
        $this->staff->is_active = false;
        $this->staff->save();
        $this->actingAs($this->owner)->put('/team/'.$this->staff->id.'/password', $this->resetPayload('incorrect'))->assertSessionHasErrors('current_password');
        $this->put('/team/'.$this->staff->id.'/password', $this->resetPayload())->assertRedirect('/team/'.$this->staff->id.'/edit');
        $updated = $this->staff->fresh();
        $this->assertTrue(Hash::check('New-Staff-Password-123!', $updated->password));
        $this->assertFalse($updated->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.password_reset']);
    }

    public function test_branch_reassignment_revokes_sessions_and_preserves_sales_history(): void
    {
        $product = Product::create(['name' => 'Rice', 'sku' => 'RICE', 'category' => 'Food', 'price_minor' => 10000, 'reorder_level' => 5]);
        Stock::create(['branch_id' => $this->branch->id, 'product_id' => $product->id, 'quantity' => 20]);
        $sale = app(SaleService::class)->record($this->staff, ['reference' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'product_id' => $product->id, 'quantity' => 1]);
        $other = Branch::create(['name' => 'Garki', 'location' => 'Abuja']);
        $this->actingAs($this->owner)->patch('/team/'.$this->staff->id, ['name' => 'Updated staff', 'email' => $this->staff->email, 'branch_id' => $other->id])->assertRedirect('/team');
        $this->assertSame($other->id, $this->staff->fresh()->branch_id);
        $this->assertSame($this->branch->id, $sale->fresh()->branch_id);
        $this->assertSame($this->staff->id, $sale->fresh()->user_id);
        $this->actingAs($this->staff)->get('/dashboard')->assertRedirect('/login');
        $this->post('/login', ['email' => $this->staff->email, 'password' => $this->staffPassword])->assertRedirect('/dashboard');
        $this->get('/sales')->assertOk()->assertDontSee(substr($sale->reference, 0, 8));
    }

    public function test_profile_update_cannot_escalate_role_or_overwrite_account_controls(): void
    {
        $data = ['name' => $this->staff->name, 'email' => $this->staff->email, 'branch_id' => $this->branch->id, 'role' => 'owner', 'is_active' => 1, 'auth_version' => 999];
        $this->actingAs($this->owner)->patch('/team/'.$this->staff->id, $data)->assertSessionHasErrors(['role', 'is_active', 'auth_version']);
        $this->assertSame('staff', $this->staff->fresh()->role);
    }

    public function test_profile_update_rejects_duplicate_email(): void
    {
        $this->actingAs($this->owner)->patch('/team/'.$this->staff->id, ['name' => 'Duplicate', 'email' => $this->owner->email, 'branch_id' => $this->branch->id])->assertSessionHasErrors('email');
    }

    public function test_unchanged_profile_does_not_sign_staff_out(): void
    {
        $version = $this->staff->auth_version;
        $this->actingAs($this->owner)->patch('/team/'.$this->staff->id, ['name' => $this->staff->name, 'email' => $this->staff->email, 'branch_id' => $this->branch->id])->assertRedirect('/team');
        $this->assertSame($version, $this->staff->fresh()->auth_version);
    }

    public function test_invalidated_sessions_cannot_call_livewire_endpoints(): void
    {
        $this->actingAs($this->staff)->withSession(['auth_version' => 0])->postJson(route('default-livewire.update'), ['components' => []])->assertUnauthorized();
    }

    public function test_inactive_account_is_rejected_by_business_authorization(): void
    {
        $this->staff->is_active = false;
        $this->staff->save();
        $this->expectException(HttpException::class);
        BranchAccess::authorize($this->staff, $this->branch->id);
    }

    public function test_legacy_sessions_without_version_require_fresh_login(): void
    {
        $this->actingAs($this->staff);
        session()->forget('auth_version');
        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_oversized_multibyte_and_null_byte_passwords_are_rejected(): void
    {
        foreach ([str_repeat('x', 73), str_repeat('界', 30), "TwelveChars123\0suffix"] as $password) {
            $this->actingAs($this->staff)->put('/account/password', [
                'current_password' => $this->staffPassword,
                'password' => $password,
                'password_confirmation' => $password,
            ])->assertSessionHasErrors('password');
        }
        $this->assertTrue(Hash::check($this->staffPassword, $this->staff->fresh()->password));
    }

    public function test_array_current_password_is_a_validation_error(): void
    {
        $this->actingAs($this->staff)->put('/account/password', [
            'current_password' => ['invalid'],
            'password' => 'Safe-New-Password-123!',
            'password_confirmation' => 'Safe-New-Password-123!',
        ])->assertSessionHasErrors('current_password');
    }
}
