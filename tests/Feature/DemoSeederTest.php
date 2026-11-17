<?php

namespace Tests\Feature;

use App\Models\ApiSource;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Import;
use App\Models\IngestionEvent;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\SaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_data_is_complete_preserves_existing_records_and_can_be_seeded_twice(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['role' => 'owner']);
        $branch = Branch::create(['name' => 'Wuse Branch', 'location' => 'Existing location']);
        $product = Product::create(['name' => 'Existing product', 'sku' => 'EXISTING', 'category' => 'Retail', 'price_minor' => 10000, 'reorder_level' => 2]);
        $stock = Stock::create(['branch_id' => $branch->id, 'product_id' => $product->id, 'quantity' => 20]);
        $sale = app(SaleService::class)->record($owner, ['reference' => (string) Str::uuid(), 'branch_id' => $branch->id, 'product_id' => $product->id, 'quantity' => 1]);

        $this->seed(DemoSeeder::class);
        $this->assertSame(720, Sale::where('source', 'demo')->count());
        $this->assertSame(18, Sale::where('source', 'demo')->where('status', 'returned')->count());
        $this->assertSame(15, Product::where('sku', 'like', 'DEMO-%')->count());
        $this->assertSame(3, User::where('role', 'staff')->count());
        $this->assertSame(3, ApiSource::count());
        $this->assertSame(3, IngestionEvent::where('status', 'completed')->count());
        $this->assertSame(3, IngestionEvent::where('status', 'rejected')->count());
        $this->assertSame(3, Import::where('processed', 1)->where('rejected', 1)->count());
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame('Existing location', $branch->fresh()->location);
        $this->assertSame(19, $stock->fresh()->quantity);
        $this->assertNotNull($sale->fresh());

        foreach (Stock::whereHas('product', fn ($q) => $q->where('sku', 'like', 'DEMO-%'))->get() as $balance) {
            $ledger = StockMovement::where('branch_id', $balance->branch_id)->where('product_id', $balance->product_id)->sum('quantity');
            $this->assertSame($balance->quantity, (int) $ledger);
        }
        $counts = $this->counts();
        $balances = Stock::orderBy('id')->pluck('quantity', 'id')->all();
        $passwords = User::orderBy('id')->pluck('password', 'id')->all();
        $keys = ApiSource::orderBy('id')->pluck('token_hash', 'id')->all();
        $this->seed(DemoSeeder::class);
        $this->assertSame($counts, $this->counts());
        $this->assertSame($balances, Stock::orderBy('id')->pluck('quantity', 'id')->all());
        $this->assertSame($passwords, User::orderBy('id')->pluck('password', 'id')->all());
        $this->assertSame($keys, ApiSource::orderBy('id')->pluck('token_hash', 'id')->all());
    }

    private function counts(): array
    {
        return collect([Branch::class, Product::class, Sale::class, Stock::class, StockMovement::class, AuditLog::class, User::class, ApiSource::class, IngestionEvent::class, Import::class])->mapWithKeys(fn ($model) => [$model => $model::count()])->all();
    }
}
