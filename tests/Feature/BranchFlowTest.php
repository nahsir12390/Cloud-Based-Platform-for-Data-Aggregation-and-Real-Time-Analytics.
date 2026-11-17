<?php

namespace Tests\Feature;

use App\Jobs\ProcessSalesImport;
use App\Livewire\Dashboard;
use App\Models\Branch;
use App\Models\Import;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class BranchFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Branch $branch;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->branch = Branch::create(['name' => 'Wuse', 'location' => 'Abuja']);
        $this->product = Product::create(['name' => 'Rice', 'sku' => 'RICE', 'category' => 'Grocery', 'price_minor' => 125099, 'reorder_level' => 5]);
        Stock::create(['branch_id' => $this->branch->id, 'product_id' => $this->product->id, 'quantity' => 20]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['branch_id' => $this->branch->id, 'product_id' => $this->product->id, 'quantity' => 2, 'reference' => (string) Str::uuid()], $overrides);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Welcome back');
    }

    public function test_owner_can_render_every_workspace_page(): void
    {
        foreach (['dashboard', 'sales', 'inventory', 'branches', 'products', 'imports', 'activity', 'team'] as $page) {
            $this->actingAs($this->owner)->get('/'.$page)->assertOk();
        }
    }

    public function test_valid_login_regenerates_session_and_authenticates(): void
    {
        $u = User::factory()->create(['password' => 'Long-Secret-123']);
        $this->post('/login', ['email' => $u->email, 'password' => 'Long-Secret-123'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($u);
    }

    public function test_failed_login_is_rejected(): void
    {
        $this->post('/login', ['email' => $this->owner->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_sale_deducts_stock_records_price_and_audit(): void
    {
        $this->actingAs($this->owner)->post('/sales', $this->payload())->assertSessionHasNoErrors();
        $this->assertDatabaseHas('stocks', ['quantity' => 18]);
        $this->assertDatabaseHas('sales', ['unit_price_minor' => 125099, 'total_minor' => 250198]);
        $this->assertDatabaseHas('stock_movements', ['quantity' => -2, 'type' => 'sale']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sale.created']);
    }

    public function test_replaying_sale_does_not_deduct_stock_twice(): void
    {
        $payload = $this->payload();
        $service = app(SaleService::class);
        $a = $service->record($this->owner, $payload);
        $b = $service->record($this->owner, $payload);
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Sale::count());
        $this->assertDatabaseHas('stocks', ['quantity' => 18]);
    }

    public function test_duplicate_reference_with_different_payload_is_rejected(): void
    {
        $payload = $this->payload();
        $this->actingAs($this->owner)->post('/sales', $payload);
        $this->post('/sales', array_merge($payload, ['quantity' => 3]))->assertSessionHasErrors('reference');
        $this->assertDatabaseHas('stocks', ['quantity' => 18]);
    }

    public function test_insufficient_stock_rolls_back_everything(): void
    {
        $this->actingAs($this->owner)->post('/sales', $this->payload(['quantity' => 21]))->assertSessionHasErrors('quantity');
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseHas('stocks', ['quantity' => 20]);
    }

    public function test_negative_and_zero_sales_are_rejected(): void
    {
        foreach ([0, -1] as $quantity) {
            $this->actingAs($this->owner)->post('/sales', $this->payload(['quantity' => $quantity]))->assertSessionHasErrors('quantity');
        }$this->assertDatabaseCount('sales', 0);
    }

    public function test_return_restores_stock_once(): void
    {
        $s = app(SaleService::class)->record($this->owner, $this->payload());
        $this->actingAs($this->owner)->post('/sales/'.$s->id.'/return')->assertSessionHasNoErrors();
        $this->post('/sales/'.$s->id.'/return')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('stocks', ['quantity' => 20]);
        $this->assertDatabaseHas('sales', ['status' => 'returned']);
        $this->assertSame(1, StockMovement::where('type', 'return')->count());
    }

    public function test_staff_cannot_sell_at_another_branch_or_manage_catalog(): void
    {
        $other = Branch::create(['name' => 'Garki', 'location' => 'Abuja']);
        $staff = User::factory()->create(['role' => 'staff', 'branch_id' => $other->id]);
        $this->actingAs($staff)->post('/sales', $this->payload())->assertForbidden();
        $this->get('/products')->assertForbidden();
        $this->post('/inventory/receive', ['branch_id' => $other->id, 'product_id' => $this->product->id, 'quantity' => 10, 'note' => 'Test'])->assertForbidden();
        $this->get('/reports/sales.csv?branch_id='.$this->branch->id)->assertForbidden();
    }

    public function test_staff_only_see_assigned_branch_transactions(): void
    {
        $s = app(SaleService::class)->record($this->owner, $this->payload());
        $other = Branch::create(['name' => 'Garki', 'location' => 'Abuja']);
        $staff = User::factory()->create(['role' => 'staff', 'branch_id' => $other->id]);
        $this->actingAs($staff)->get('/sales')->assertOk()->assertDontSee(substr($s->reference, 0, 8));
        $this->get('/reports/sales.csv')->assertStreamedContent("reference,branch,sku,quantity,unit_price_ngn,total_ngn,status,sold_at\n");
    }

    public function test_staff_can_sell_only_at_assigned_branch(): void
    {
        $staff = User::factory()->create(['role' => 'staff', 'branch_id' => $this->branch->id]);
        $this->actingAs($staff)->post('/sales', $this->payload())->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sales', ['user_id' => $staff->id]);
    }

    public function test_receipt_creates_stock_movement(): void
    {
        $this->actingAs($this->owner)->post('/inventory/receive', ['branch_id' => $this->branch->id, 'product_id' => $this->product->id, 'quantity' => 50, 'note' => 'Delivery 103'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('stocks', ['quantity' => 70]);
        $this->assertDatabaseHas('stock_movements', ['type' => 'receipt', 'quantity' => 50]);
    }

    public function test_creating_branch_and_product_initializes_stock_balances(): void
    {
        $this->actingAs($this->owner)->post('/branches', ['name' => 'Kubwa', 'location' => 'Abuja'])->assertSessionHasNoErrors();
        $b = Branch::where('name', 'Kubwa')->firstOrFail();
        $this->assertDatabaseHas('stocks', ['branch_id' => $b->id, 'product_id' => $this->product->id, 'quantity' => 0]);
        $this->post('/products', ['name' => 'Oil', 'sku' => 'oil', 'category' => 'Grocery', 'price' => '12.34', 'reorder_level' => 3])->assertSessionHasNoErrors();
        $p = Product::where('sku', 'OIL')->firstOrFail();
        $this->assertSame(1234, $p->price_minor);
        $this->assertSame(2, Stock::where('product_id', $p->id)->count());
    }

    public function test_dashboard_rejects_staff_filter_tampering(): void
    {
        $other = Branch::create(['name' => 'Other', 'location' => 'Abuja']);
        $staff = User::factory()->create(['role' => 'staff', 'branch_id' => $other->id]);
        $this->withSession(['auth_version' => $staff->auth_version]);
        Livewire::actingAs($staff)->test(Dashboard::class)->set('branch', (string) $this->branch->id)->assertForbidden();
    }

    public function test_dashboard_aggregates_and_excludes_returns(): void
    {
        $s = app(SaleService::class)->record($this->owner, $this->payload());
        $this->withSession(['auth_version' => $this->owner->auth_version]);
        Livewire::actingAs($this->owner)->test(Dashboard::class)->assertSee('2,501.98');
        app(SaleService::class)->reverse($this->owner, $s);
        $this->withSession(['auth_version' => $this->owner->auth_version]);
        Livewire::actingAs($this->owner)->test(Dashboard::class)->assertSee('₦0.00')->assertDontSee('2,501.98');
    }

    public function test_csv_import_records_valid_rows_and_reports_bad_rows(): void
    {
        Storage::fake('local');
        $id = (string) Str::uuid();
        $content = "reference,sku,quantity,sold_at\n$id,RICE,2,".now()->subMinute()->toDateTimeString()."\n".Str::uuid().',MISSING,2,'.now()->subMinute()->toDateTimeString()."\n";
        Storage::put('imports/test.csv', $content);
        $import = Import::create(['user_id' => $this->owner->id, 'branch_id' => $this->branch->id, 'filename' => 'test.csv', 'path' => 'imports/test.csv']);
        (new ProcessSalesImport($import->id))->handle(app(SaleService::class));
        $import->refresh();
        $this->assertSame('completed', $import->status);
        $this->assertSame(1, $import->processed);
        $this->assertSame(1, $import->rejected);
        $this->assertDatabaseHas('stocks', ['quantity' => 18]);
        $import->update(['status' => 'queued']);
        (new ProcessSalesImport($import->id))->handle(app(SaleService::class));
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('stocks', ['quantity' => 18]);
    }

    public function test_csv_import_rejects_wrong_headers(): void
    {
        Storage::fake('local');
        Storage::put('imports/bad.csv', "sku,quantity\nRICE,2\n");
        $i = Import::create(['user_id' => $this->owner->id, 'branch_id' => $this->branch->id, 'filename' => 'bad.csv', 'path' => 'imports/bad.csv']);
        (new ProcessSalesImport($i->id))->handle(app(SaleService::class));
        $this->assertSame('failed', $i->fresh()->status);
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_csv_exports_correct_currency_units(): void
    {
        app(SaleService::class)->record($this->owner, $this->payload());
        $response = $this->actingAs($this->owner)->get('/reports/sales.csv');
        $response->assertOk();
        $this->assertStringContainsString('1250.99,2501.98,completed', $response->streamedContent());
    }

    public function test_staff_cannot_return_sales(): void
    {
        $s = app(SaleService::class)->record($this->owner, $this->payload());
        $staff = User::factory()->create(['role' => 'staff', 'branch_id' => $this->branch->id]);
        $this->actingAs($staff)->post('/sales/'.$s->id.'/return')->assertForbidden();
        $this->assertDatabaseHas('stocks', ['quantity' => 18]);
    }

    public function test_uuid_case_does_not_create_a_second_sale(): void
    {
        $payload = $this->payload();
        $service = app(SaleService::class);
        $service->record($this->owner, array_merge($payload, ['reference' => strtoupper($payload['reference'])]));
        $service->record($this->owner, $payload);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('stocks', ['quantity' => 18]);
    }

    public function test_timestamp_changes_are_not_treated_as_matching_replays(): void
    {
        $payload = $this->payload(['sold_at' => now()->subDays(2)->toIso8601String()]);
        $service = app(SaleService::class);
        $service->record($this->owner, $payload);
        try {
            $service->record($this->owner, array_merge($payload, ['sold_at' => now()->subDay()->toIso8601String()]));
            $this->fail('Changed timestamp should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reference', $exception->errors());
        }
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('stocks', ['quantity' => 18]);
    }
}
