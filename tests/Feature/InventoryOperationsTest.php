<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\StockOperation;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $staff;

    protected Branch $from;

    protected Branch $to;

    protected Branch $other;

    protected Product $product;

    protected Stock $balance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->from = Branch::create(['name' => 'Wuse', 'location' => 'Abuja']);
        $this->to = Branch::create(['name' => 'Garki', 'location' => 'Abuja']);
        $this->other = Branch::create(['name' => 'Kubwa', 'location' => 'Abuja']);
        $this->staff = User::factory()->create(['role' => 'staff', 'branch_id' => $this->from->id]);
        $this->product = Product::create(['name' => 'Rice', 'sku' => 'RICE', 'category' => 'Food', 'price_minor' => 125099, 'reorder_level' => 5]);
        $this->balance = Stock::create(['branch_id' => $this->from->id, 'product_id' => $this->product->id, 'quantity' => 20]);
        Stock::create(['branch_id' => $this->to->id, 'product_id' => $this->product->id, 'quantity' => 8]);
        Stock::create(['branch_id' => $this->other->id, 'product_id' => $this->product->id, 'quantity' => 11]);
    }

    private function transfer(array $changes = []): array
    {
        return array_merge(['reference' => (string) Str::uuid(), 'branch_id' => $this->from->id, 'destination_branch_id' => $this->to->id, 'product_id' => $this->product->id, 'quantity' => 5, 'note' => 'Dispatch TR-100 for branch replenishment'], $changes);
    }

    private function adjustment(array $changes = []): array
    {
        return array_merge(['reference' => (string) Str::uuid(), 'expected_quantity' => 20, 'counted_quantity' => 18, 'reason' => 'damaged', 'note' => 'Two damaged packs removed during counting'], $changes);
    }

    private function stock(int $branch): int
    {
        return Stock::where('branch_id', $branch)->where('product_id', $this->product->id)->firstOrFail()->quantity;
    }

    public function test_owner_can_render_transfers_adjustment_and_product_editor(): void
    {
        $this->actingAs($this->owner)->get('/inventory/transfers')->assertOk()->assertSee('Record a branch transfer');
        $this->get('/inventory/'.$this->balance->id.'/adjust')->assertOk()->assertSee('Record a stock correction');
        $this->get('/products/'.$this->product->id.'/edit')->assertOk()->assertSee('Save product changes');
    }

    public function test_transfer_conserves_stock_and_writes_both_ledger_sides(): void
    {
        $this->actingAs($this->owner)->post('/inventory/transfers', $this->transfer())->assertRedirect('/inventory/transfers');
        $this->assertSame(15, $this->stock($this->from->id));
        $this->assertSame(13, $this->stock($this->to->id));
        $this->assertSame(28, $this->stock($this->from->id) + $this->stock($this->to->id));
        $operation = StockOperation::firstOrFail();
        $this->assertSame(2, $operation->movements()->count());
        $this->assertSame(0, (int) $operation->movements()->sum('quantity'));
        $this->assertDatabaseHas('stock_movements', ['stock_operation_id' => $operation->id, 'type' => 'transfer_out', 'quantity' => -5]);
        $this->assertDatabaseHas('stock_movements', ['stock_operation_id' => $operation->id, 'type' => 'transfer_in', 'quantity' => 5]);
        $this->assertDatabaseHas('audit_logs', ['branch_id' => $this->from->id, 'action' => 'stock.transferred_out']);
        $this->assertDatabaseHas('audit_logs', ['branch_id' => $this->to->id, 'action' => 'stock.transferred_in']);
    }

    public function test_transfer_replay_is_idempotent_and_uuid_case_is_normalized(): void
    {
        $payload = $this->transfer();
        $service = app(InventoryService::class);
        $a = $service->transfer($this->owner, $payload);
        $b = $service->transfer($this->owner, array_merge($payload, ['reference' => strtoupper($payload['reference'])]));
        $this->assertSame($a->id, $b->id);
        $this->assertDatabaseCount('stock_operations', 1);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertSame(15, $this->stock($this->from->id));
    }

    public function test_changed_replay_is_rejected_without_additional_changes(): void
    {
        $payload = $this->transfer();
        $this->actingAs($this->owner)->post('/inventory/transfers', $payload);
        $this->post('/inventory/transfers', array_merge($payload, ['quantity' => 6]))->assertSessionHasErrors('reference');
        $this->assertSame(15, $this->stock($this->from->id));
        $this->assertSame(13, $this->stock($this->to->id));
    }

    public function test_overselling_transfer_rolls_back_everything(): void
    {
        $this->actingAs($this->owner)->post('/inventory/transfers', $this->transfer(['quantity' => 21]))->assertSessionHasErrors('quantity');
        $this->assertDatabaseCount('stock_operations', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame(20, $this->stock($this->from->id));
        $this->assertSame(8, $this->stock($this->to->id));
    }

    public function test_same_branch_and_invalid_quantity_transfers_are_rejected(): void
    {
        $this->actingAs($this->owner)->post('/inventory/transfers', $this->transfer(['destination_branch_id' => $this->from->id]))->assertSessionHasErrors('destination_branch_id');
        foreach ([0, -1, 1.5] as $quantity) {
            $this->post('/inventory/transfers', $this->transfer(['quantity' => $quantity]))->assertSessionHasErrors('quantity');
        }$this->assertDatabaseCount('stock_operations', 0);
    }

    public function test_missing_destination_balance_does_not_deduct_source_stock(): void
    {
        Stock::where('branch_id', $this->to->id)->delete();
        $this->actingAs($this->owner)->post('/inventory/transfers', $this->transfer())->assertSessionHasErrors('product_id');
        $this->assertSame(20, $this->stock($this->from->id));
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_receiving_balance_limit_is_enforced(): void
    {
        Stock::where('branch_id', $this->to->id)->update(['quantity' => 1000000000]);
        $this->actingAs($this->owner)->post('/inventory/transfers', $this->transfer())->assertSessionHasErrors('quantity');
        $this->assertSame(20, $this->stock($this->from->id));
    }

    public function test_adjustment_updates_absolute_balance_and_preserves_reason(): void
    {
        $payload = $this->adjustment();
        $this->actingAs($this->owner)->post('/inventory/'.$this->balance->id.'/adjust', $payload)->assertRedirect('/inventory/'.$this->balance->id.'/adjust');
        $this->assertSame(18, $this->stock($this->from->id));
        $this->assertDatabaseHas('stock_operations', ['kind' => 'adjustment', 'quantity' => -2, 'expected_quantity' => 20, 'resulting_quantity' => 18, 'reason' => 'damaged', 'note' => $payload['note']]);
        $this->assertDatabaseHas('stock_movements', ['type' => 'adjustment', 'quantity' => -2]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'stock.adjusted']);
    }

    public function test_stock_count_can_increase_balance_or_set_it_to_zero(): void
    {
        $this->actingAs($this->owner)->post('/inventory/'.$this->balance->id.'/adjust', $this->adjustment(['reason' => 'stock_count', 'counted_quantity' => 25]))->assertSessionHasNoErrors();
        $this->assertSame(25, $this->stock($this->from->id));
        $this->post('/inventory/'.$this->balance->id.'/adjust', $this->adjustment(['expected_quantity' => 25, 'reason' => 'stock_count', 'counted_quantity' => 0]))->assertSessionHasNoErrors();
        $this->assertSame(0, $this->stock($this->from->id));
        $this->assertSame(-20, (int) StockMovement::sum('quantity'));
    }

    public function test_damaged_or_expired_adjustment_cannot_increase_stock(): void
    {
        foreach (['damaged', 'expired'] as $reason) {
            $this->actingAs($this->owner)->post('/inventory/'.$this->balance->id.'/adjust', $this->adjustment(['reason' => $reason, 'counted_quantity' => 21]))->assertSessionHasErrors('reason');
        }$this->assertDatabaseCount('stock_operations', 0);
        $this->assertSame(20, $this->stock($this->from->id));
    }

    public function test_stale_adjustment_is_rejected_after_a_transfer(): void
    {
        app(InventoryService::class)->transfer($this->owner, $this->transfer(['quantity' => 1]));
        $this->actingAs($this->owner)->post('/inventory/'.$this->balance->id.'/adjust', $this->adjustment())->assertSessionHasErrors('counted_quantity');
        $this->assertSame(19, $this->stock($this->from->id));
        $this->assertDatabaseCount('stock_operations', 1);
    }

    public function test_adjustment_replay_does_not_overwrite_later_movements(): void
    {
        $payload = $this->adjustment();
        $service = app(InventoryService::class);
        $operation = $service->adjust($this->owner, $this->balance, $payload);
        $service->transfer($this->owner, $this->transfer(['quantity' => 2]));
        $repeat = $service->adjust($this->owner, $this->balance, $payload);
        $this->assertSame($operation->id, $repeat->id);
        $this->assertSame(16, $this->stock($this->from->id));
        $this->assertDatabaseCount('stock_operations', 2);
        $this->assertDatabaseCount('stock_movements', 3);
    }

    public function test_negative_or_unchanged_counts_and_missing_notes_are_rejected(): void
    {
        $this->actingAs($this->owner)->post('/inventory/'.$this->balance->id.'/adjust', $this->adjustment(['counted_quantity' => -1]))->assertSessionHasErrors('counted_quantity');
        $this->post('/inventory/'.$this->balance->id.'/adjust', $this->adjustment(['counted_quantity' => 20]))->assertSessionHasErrors('counted_quantity');
        $this->post('/inventory/'.$this->balance->id.'/adjust', $this->adjustment(['note' => '']))->assertSessionHasErrors('note');
        $this->assertDatabaseCount('stock_operations', 0);
    }

    public function test_staff_can_only_read_transfers_involving_their_branch(): void
    {
        $service = app(InventoryService::class);
        $visible = $service->transfer($this->owner, $this->transfer());
        $hidden = $service->transfer($this->owner, $this->transfer(['branch_id' => $this->to->id, 'destination_branch_id' => $this->other->id, 'quantity' => 1, 'note' => 'Private Garki to Kubwa movement']));
        $this->actingAs($this->staff)->get('/inventory/transfers')->assertOk()->assertSee(substr($visible->reference, 0, 8))->assertDontSee(substr($hidden->reference, 0, 8))->assertDontSee('Record a branch transfer');
        $this->get('/inventory/transfers?branch_id='.$this->to->id)->assertForbidden();
    }

    public function test_receiving_staff_can_see_incoming_transfer(): void
    {
        $operation = app(InventoryService::class)->transfer($this->owner, $this->transfer());
        $staff = User::factory()->create(['role' => 'staff', 'branch_id' => $this->to->id]);
        $this->actingAs($staff)->get('/inventory/transfers')->assertOk()->assertSee(substr($operation->reference, 0, 8));
    }

    public function test_staff_cannot_transfer_adjust_or_edit_products(): void
    {
        $this->actingAs($this->staff)->post('/inventory/transfers', $this->transfer())->assertForbidden();
        $this->get('/inventory/'.$this->balance->id.'/adjust')->assertForbidden();
        $this->post('/inventory/'.$this->balance->id.'/adjust', $this->adjustment())->assertForbidden();
        $this->get('/products/'.$this->product->id.'/edit')->assertForbidden();
        $this->patch('/products/'.$this->product->id, ['name' => 'Unauthorized'])->assertForbidden();
        $this->assertDatabaseCount('stock_operations', 0);
    }

    public function test_product_update_preserves_old_sale_prices_and_applies_to_new_sales(): void
    {
        $before = app(SaleService::class)->record($this->owner, ['reference' => (string) Str::uuid(), 'branch_id' => $this->from->id, 'product_id' => $this->product->id, 'quantity' => 1]);
        $this->actingAs($this->owner)->patch('/products/'.$this->product->id, ['name' => 'Premium Rice', 'category' => 'Groceries', 'price' => '1350.01', 'reorder_level' => 12])->assertRedirect('/products');
        $this->assertSame(135001, $this->product->fresh()->price_minor);
        $this->assertSame(125099, $before->fresh()->unit_price_minor);
        $after = app(SaleService::class)->record($this->owner, ['reference' => (string) Str::uuid(), 'branch_id' => $this->from->id, 'product_id' => $this->product->id, 'quantity' => 1]);
        $this->assertSame(135001, (int) $after->unit_price_minor);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.updated']);
    }

    public function test_product_edit_rejects_sku_and_invalid_prices(): void
    {
        $payload = ['name' => 'Rice', 'category' => 'Food', 'price' => '10.00', 'reorder_level' => 5];
        $this->actingAs($this->owner)->patch('/products/'.$this->product->id, $payload + ['sku' => 'CHANGED'])->assertSessionHasErrors('sku');
        foreach (['0', '-1', '1.001', '1e3'] as $price) {
            $this->patch('/products/'.$this->product->id, array_merge($payload, ['price' => $price]))->assertSessionHasErrors('price');
        }$this->assertSame('RICE', $this->product->fresh()->sku);
        $this->assertSame(125099, $this->product->fresh()->price_minor);
    }

    public function test_inventory_search_stock_filters_and_branch_permissions(): void
    {
        Stock::where('branch_id', $this->to->id)->update(['quantity' => 0]);
        $this->actingAs($this->owner)->get('/inventory?q=rice&stock_status=out')->assertOk()->assertViewHas('stocks', fn ($stocks) => $stocks->total() === 1 && $stocks->first()->branch_id === $this->to->id);
        $this->get('/inventory?stock_status=healthy')->assertOk()->assertViewHas('stocks', fn ($stocks) => $stocks->total() === 2);
        $this->get('/inventory?q=not-found')->assertOk()->assertViewHas('stocks', fn ($stocks) => $stocks->total() === 0);
        $this->actingAs($this->staff)->get('/inventory?branch_id='.$this->to->id)->assertForbidden();
    }

    public function test_failure_after_balance_changes_rolls_back_both_balances_and_ledger(): void
    {
        StockMovement::creating(function ($movement) {
            if ($movement->type === 'transfer_in') {
                throw new \RuntimeException('Simulated ledger write failure');
            }
        });
        try {
            app(InventoryService::class)->transfer($this->owner, $this->transfer());
            $this->fail('The ledger failure must abort the transfer.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated ledger write failure', $exception->getMessage());
        }
        $this->assertSame(20, $this->stock($this->from->id));
        $this->assertSame(8, $this->stock($this->to->id));
        $this->assertDatabaseCount('stock_operations', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_decimal_product_prices_convert_exactly_to_kobo(): void
    {
        foreach (['0.29' => 29, '12.3' => 1230, '100000000.00' => 10000000000] as $price => $minor) {
            $this->actingAs($this->owner)->patch('/products/'.$this->product->id, [
                'name' => 'Rice', 'category' => 'Food', 'price' => $price, 'reorder_level' => 5,
            ])->assertSessionHasNoErrors();
            $this->assertSame($minor, $this->product->fresh()->price_minor);
        }
    }
}
