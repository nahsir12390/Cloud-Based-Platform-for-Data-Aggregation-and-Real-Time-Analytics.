<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesReportingTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $staff;

    protected Branch $branch;

    protected Branch $other;

    protected Product $rice;

    protected Product $oil;

    protected array $sales = [];

    protected string $date;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->branch = Branch::create(['name' => 'Wuse', 'location' => 'Abuja']);
        $this->other = Branch::create(['name' => 'Garki', 'location' => 'Abuja']);
        $this->staff = User::factory()->create(['role' => 'staff', 'branch_id' => $this->branch->id]);
        $this->rice = Product::create(['name' => 'Rice', 'sku' => 'RICE', 'category' => 'Food', 'price_minor' => 100000, 'reorder_level' => 5]);
        $this->oil = Product::create(['name' => 'Cooking Oil', 'sku' => 'OIL', 'category' => 'Food', 'price_minor' => 250000, 'reorder_level' => 5]);
        foreach ([$this->branch, $this->other] as $branch) {
            foreach ([$this->rice, $this->oil] as $product) {
                Stock::create(['branch_id' => $branch->id, 'product_id' => $product->id, 'quantity' => 100]);
            }
        }$this->date = now()->subDay()->toDateString();
        $specs = [[$this->branch, $this->rice, 2, $this->date.' 00:00:00', 'web'], [$this->branch, $this->rice, 1, $this->date.' 23:59:59', 'api'], [$this->branch, $this->oil, 1, $this->date.' 12:00:00', 'csv'], [$this->other, $this->rice, 3, $this->date.' 14:00:00', 'demo'], [$this->branch, $this->oil, 1, now()->toDateString().' 00:00:00', 'web']];
        foreach ($specs as [$branch,$product,$quantity,$time,$source]) {
            $this->sales[] = app(SaleService::class)->record($this->owner, ['reference' => (string) Str::uuid(), 'branch_id' => $branch->id, 'product_id' => $product->id, 'quantity' => $quantity, 'sold_at' => $time], $source);
        }app(SaleService::class)->reverse($this->owner, $this->sales[2]);
    }

    private function csv(string $query = ''): array
    {
        $response = $this->get('/reports/sales.csv'.($query ? '?'.$query : ''))->assertOk();
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $response->streamedContent());
        rewind($handle);
        $rows = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $rows[] = $row;
        }fclose($handle);
        array_shift($rows);

        return $rows;
    }

    public function test_report_totals_exclude_returns_and_rank_completed_sales(): void
    {
        $this->actingAs($this->owner)->get('/reports')->assertOk()->assertViewHas('summary', ['matched_sales' => 5, 'revenue_minor' => 850000, 'units' => 7, 'returned_minor' => 250000])->assertViewHas('byBranch', fn ($rows) => $rows->count() === 2 && $rows->first()->name === 'Wuse' && (int) $rows->first()->revenue_minor === 550000)->assertViewHas('byProduct', fn ($rows) => $rows->first()->sku === 'RICE' && (int) $rows->first()->units === 6);
    }

    public function test_date_boundaries_include_entire_day_and_exclude_following_midnight(): void
    {
        $query = 'from='.$this->date.'&to='.$this->date;
        $this->actingAs($this->owner)->get('/sales?'.$query)->assertOk()->assertViewHas('sales', fn ($rows) => $rows->total() === 4)->assertViewHas('salesSummary', fn ($s) => $s['revenue_minor'] === 600000);
        $references = array_column($this->csv($query), 0);
        $this->assertContains($this->sales[0]->reference, $references);
        $this->assertContains($this->sales[1]->reference, $references);
        $this->assertNotContains($this->sales[4]->reference, $references);
    }

    public function test_end_date_works_without_start_date(): void
    {
        $this->actingAs($this->owner)->get('/reports?to='.$this->date)->assertOk()->assertViewHas('summary', fn ($s) => $s['matched_sales'] === 4);
    }

    public function test_filters_match_between_list_summary_and_csv(): void
    {
        $query = http_build_query(['branch_id' => $this->branch->id, 'product_id' => $this->rice->id, 'source' => 'api', 'status' => 'completed', 'from' => $this->date, 'to' => $this->date]);
        $this->actingAs($this->owner)->get('/sales?'.$query)->assertOk()->assertViewHas('sales', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $this->sales[1]->id)->assertViewHas('salesSummary', fn ($s) => $s['revenue_minor'] === 100000);
        $rows = $this->csv($query);
        $this->assertCount(1, $rows);
        $this->assertSame($this->sales[1]->reference, $rows[0][0]);
        $this->get('/reports?'.$query)->assertOk()->assertViewHas('summary', fn ($s) => $s['matched_sales'] === 1 && $s['revenue_minor'] === 100000);
    }

    public function test_search_matches_reference_prefix_name_and_sku_case_insensitively(): void
    {
        $this->actingAs($this->owner)->get('/sales?q='.strtoupper(substr($this->sales[0]->reference, 0, 8)))->assertOk()->assertViewHas('sales', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $this->sales[0]->id);
        $this->get('/sales?q=oil')->assertOk()->assertViewHas('sales', fn ($rows) => $rows->total() === 2);
        $this->get('/sales?q=rice')->assertOk()->assertViewHas('sales', fn ($rows) => $rows->total() === 3);
    }

    public function test_staff_search_summary_csv_and_detail_cannot_escape_branch_scope(): void
    {
        $this->actingAs($this->staff)->get('/reports')->assertOk()->assertViewHas('summary', ['matched_sales' => 4, 'revenue_minor' => 550000, 'units' => 4, 'returned_minor' => 250000]);
        $this->get('/sales?q=rice')->assertOk()->assertViewHas('sales', fn ($rows) => $rows->total() === 2);
        $this->assertCount(4, $this->csv());
        foreach (['/reports', '/sales', '/reports/sales.csv'] as $path) {
            $this->get($path.'?branch_id='.$this->other->id)->assertForbidden();
        }$this->get('/sales/'.$this->sales[3]->id)->assertForbidden();
    }

    public function test_returned_filter_has_no_completed_revenue_or_rankings(): void
    {
        $this->actingAs($this->owner)->get('/reports?status=returned')->assertOk()->assertViewHas('summary', ['matched_sales' => 1, 'revenue_minor' => 0, 'units' => 0, 'returned_minor' => 250000])->assertViewHas('byBranch', fn ($rows) => $rows->isEmpty())->assertViewHas('byProduct', fn ($rows) => $rows->isEmpty());
        $rows = $this->csv('status=returned');
        $this->assertCount(1, $rows);
        $this->assertSame('returned', $rows[0][6]);
    }

    public function test_sort_order_matches_export(): void
    {
        $this->actingAs($this->owner)->get('/sales?product_id='.$this->rice->id.'&sort=amount_asc')->assertOk()->assertViewHas('sales', fn ($rows) => $rows->pluck('id')->all() === [$this->sales[1]->id, $this->sales[0]->id, $this->sales[3]->id]);
        $rows = $this->csv('product_id='.$this->rice->id.'&sort=amount_asc');
        $this->assertSame([$this->sales[1]->reference, $this->sales[0]->reference, $this->sales[3]->reference], array_column($rows, 0));
    }

    public function test_invalid_dates_status_sort_and_source_are_rejected(): void
    {
        foreach (['from=not-a-date', 'from=2026-02-31', 'from=2026-11-12&to=2026-11-11', 'status=invalid', 'source=invalid', 'sort=invalid'] as $query) {
            $this->actingAs($this->owner)->get('/reports?'.$query)->assertSessionHasErrors();
        }
    }

    public function test_empty_results_have_zero_summaries_and_header_only_export(): void
    {
        $this->actingAs($this->owner)->get('/reports?q=does-not-exist')->assertOk()->assertViewHas('summary', ['matched_sales' => 0, 'revenue_minor' => 0, 'units' => 0, 'returned_minor' => 0]);
        $this->assertSame([], $this->csv('q=does-not-exist'));
    }

    public function test_sale_detail_shows_recorded_price_and_linked_movements(): void
    {
        $this->actingAs($this->owner)->get('/sales/'.$this->sales[0]->id)->assertOk()->assertSee($this->sales[0]->reference)->assertSee('₦2,000.00')->assertSee('Return full sale')->assertViewHas('movements', fn ($rows) => $rows->count() === 1 && $rows->first()->quantity === -2);
        $this->actingAs($this->staff)->get('/sales/'.$this->sales[0]->id)->assertOk()->assertDontSee('Return full sale');
    }

    public function test_export_protects_formula_like_cells(): void
    {
        $this->branch->update(['name' => ' =1+1']);
        $this->actingAs($this->owner);
        $rows = $this->csv('branch_id='.$this->branch->id);
        foreach ($rows as $row) {
            $this->assertSame("' =1+1", $row[1]);
        }
    }

    public function test_pagination_keeps_filters_and_export_includes_all_matching_pages(): void
    {
        for ($i = 0; $i < 21; $i++) {
            app(SaleService::class)->record($this->owner, ['reference' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'product_id' => $this->rice->id, 'quantity' => 1]);
        }$query = 'branch_id='.$this->branch->id.'&product_id='.$this->rice->id.'&status=completed';
        $this->actingAs($this->owner)->get('/sales?'.$query)->assertOk()->assertViewHas('sales', function ($rows) {
            return $rows->total() === 23 && $rows->count() === 20 && str_contains($rows->nextPageUrl(), 'status=completed');
        })->assertViewHas('salesSummary',fn ($s) => $s['matched_sales'] === 23);
        $this->assertCount(23,$this->csv($query));
    }
}
