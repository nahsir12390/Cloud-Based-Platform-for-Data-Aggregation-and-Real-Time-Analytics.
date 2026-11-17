<?php

namespace Tests\Feature;

use App\Jobs\ProcessSalesImport;
use App\Models\Branch;
use App\Models\Import;
use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DurableImportTest extends TestCase
{
    use RefreshDatabase;

    private function setupImport(): array
    {
        Storage::fake('local');
        $owner = User::factory()->create(['role' => 'owner']);
        $branch = Branch::create(['name' => 'Upload Branch', 'location' => 'Abuja']);
        $product = Product::create(['name' => 'Rice', 'sku' => 'RICE', 'category' => 'Food', 'price_minor' => 10000, 'reorder_level' => 5]);
        Stock::create(['branch_id' => $branch->id, 'product_id' => $product->id, 'quantity' => 20]);
        $csv = 'reference,sku,quantity,sold_at'."\n".Str::uuid().',RICE,2,'.now()->subMinute()->toIso8601String()."\n";

        return [$owner, $branch, $csv];
    }

    public function test_pending_csv_survives_loss_of_the_local_upload_file(): void
    {
        [$owner,$branch,$csv] = $this->setupImport();
        $this->actingAs($owner)->post('/imports', ['branch_id' => $branch->id, 'file' => UploadedFile::fake()->createWithContent('sales.csv', $csv)])->assertSessionHasNoErrors();
        $import = Import::firstOrFail();
        $this->assertSame($csv, $import->content);
        $this->assertArrayNotHasKey('content', $import->toArray());
        $this->assertDatabaseCount('jobs', 1);
        Storage::delete($import->path);
        (new ProcessSalesImport($import->id))->handle(app(SaleService::class));
        $this->assertSame('completed', $import->fresh()->status);
        $this->assertSame(1, $import->fresh()->processed);
        $this->assertNull($import->fresh()->content);
        $this->assertDatabaseHas('stocks', ['branch_id' => $branch->id, 'quantity' => 18]);
        $this->assertDatabaseCount('sales', 1);
    }

    public function test_upload_and_job_acceptance_roll_back_together(): void
    {
        [$owner,$branch,$csv] = $this->setupImport();
        DB::beginTransaction();
        $this->actingAs($owner)->post('/imports', ['branch_id' => $branch->id, 'file' => UploadedFile::fake()->createWithContent('sales.csv', $csv)])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('imports', 1);
        $this->assertDatabaseCount('jobs', 1);
        DB::rollBack();
        $this->assertDatabaseCount('imports', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_non_utf8_csv_is_rejected_before_database_acceptance(): void
    {
        [$owner,$branch] = $this->setupImport();
        $csv = "reference,sku,quantity,sold_at\n\xff,RICE,1,2026-01-01\n";
        $this->actingAs($owner)->post('/imports', ['branch_id' => $branch->id, 'file' => UploadedFile::fake()->createWithContent('bad.csv', $csv)])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('imports', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_failed_processing_retains_durable_content_for_retry(): void
    {
        [$owner,$branch,$csv] = $this->setupImport();
        $import = Import::create(['user_id' => $owner->id, 'branch_id' => $branch->id, 'filename' => 'bad.csv', 'path' => 'missing.csv', 'content' => 'incorrect,header'."\n"]);
        (new ProcessSalesImport($import->id))->handle(app(SaleService::class));
        $this->assertSame('failed', $import->fresh()->status);
        $this->assertNotNull($import->fresh()->content);
        $this->assertDatabaseCount('sales',0);
    }
}
