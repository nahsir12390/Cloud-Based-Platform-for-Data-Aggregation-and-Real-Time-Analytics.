<?php

namespace Tests\Feature;

use App\Jobs\ProcessIngestion;
use App\Livewire\IngestionMonitor;
use App\Models\ApiSource;
use App\Models\Branch;
use App\Models\IngestionEvent;
use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use App\Services\SaleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class IngestionTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Branch $branch;

    protected Product $product;

    protected ApiSource $source;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->branch = Branch::create(['name' => 'API Branch', 'location' => 'Abuja']);
        $this->product = Product::create(['name' => 'Rice', 'sku' => 'RICE', 'category' => 'Food', 'price_minor' => 125099, 'reorder_level' => 5]);
        Stock::create(['branch_id' => $this->branch->id, 'product_id' => $this->product->id, 'quantity' => 20]);
        $this->token = 'bf_'.bin2hex(random_bytes(32));
        $this->source = $this->createSource($this->token);
    }

    private function createSource(string $token): ApiSource
    {
        return ApiSource::create(['name' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'user_id' => $this->owner->id, 'token_hash' => hash('sha256', $token), 'token_prefix' => substr($token, 0, 11)]);
    }

    private function payload(array $changes = []): array
    {
        return array_merge(['reference' => (string) Str::uuid(), 'sku' => 'RICE', 'quantity' => 2, 'sold_at' => now()->subMinute()->format('Y-m-d\TH:i:sP')], $changes);
    }

    private function headers(?string $token = null): array
    {
        return ['Authorization' => 'Bearer '.($token ?? $this->token)];
    }

    private function submit(array $payload)
    {
        return $this->postJson('/api/v1/sales', $payload, $this->headers());
    }

    private function process(string $reference): IngestionEvent
    {
        $event = IngestionEvent::where('reference', $reference)->firstOrFail();
        (new ProcessIngestion($event->id))->handle(app(SaleService::class));

        return $event->fresh();
    }

    public function test_api_requires_a_valid_key_even_with_owner_session(): void
    {
        $this->postJson('/api/v1/sales', $this->payload())->assertUnauthorized();
        $this->actingAs($this->owner)->postJson('/api/v1/sales', $this->payload())->assertUnauthorized();
        $this->postJson('/api/v1/sales', $this->payload(), $this->headers('bf_'.str_repeat('0', 64)))->assertUnauthorized();
    }

    public function test_acceptance_persists_event_and_job_without_deducting_stock(): void
    {
        $payload = $this->payload();
        $this->submit($payload)->assertAccepted()->assertJsonPath('data.status', 'queued')->assertJsonPath('duplicate', false)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('ingestion_events', 1);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseHas('stocks', ['quantity' => 20]);
        $this->assertNotNull($this->source->fresh()->last_seen_at);
    }

    public function test_worker_records_sale_and_completes_event_once(): void
    {
        $payload = $this->payload();
        $this->submit($payload);
        $event = $this->process($payload['reference']);
        $this->assertSame('completed', $event->status);
        $this->assertNotNull($event->processed_at);
        $this->assertDatabaseHas('sales', ['source' => 'api', 'total_minor' => 250198, 'branch_id' => $this->branch->id]);
        $this->assertDatabaseHas('stocks', ['quantity' => 18]);
        (new ProcessIngestion($event->id))->handle(app(SaleService::class));
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('stocks', ['quantity' => 18]);
    }

    public function test_worker_rejects_overselling_without_creating_sale(): void
    {
        $payload = $this->payload(['quantity' => 21]);
        $this->submit($payload)->assertAccepted();
        $event = $this->process($payload['reference']);
        $this->assertSame('rejected', $event->status);
        $this->assertStringContainsString('enough stock', $event->error);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseHas('stocks', ['quantity' => 20]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ingestion.rejected']);
    }

    public function test_duplicate_submissions_queue_only_one_job(): void
    {
        $payload = $this->payload();
        $this->submit($payload)->assertAccepted();
        $this->submit($payload)->assertAccepted()->assertJsonPath('duplicate', true);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('ingestion_events', 1);
        $this->process($payload['reference']);
        $this->submit($payload)->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('duplicate', true);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_changed_duplicate_payload_is_a_conflict(): void
    {
        $payload = $this->payload();
        $this->submit($payload);
        $this->submit(array_merge($payload, ['quantity' => 3]))->assertConflict();
        $this->submit(array_merge($payload, ['sold_at' => now()->subDays(2)->format('Y-m-d\TH:i:sP')]))->assertConflict();
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_same_instant_with_different_offset_is_a_matching_replay(): void
    {
        $payload = $this->payload();
        $this->submit($payload);
        $payload['sold_at'] = Carbon::parse($payload['sold_at'])->utc()->format('Y-m-d\TH:i:s\Z');
        $this->submit($payload)->assertAccepted()->assertJsonPath('duplicate', true);
        $this->assertDatabaseCount('ingestion_events', 1);
    }

    public function test_client_cannot_override_branch_or_prices(): void
    {
        foreach (['branch_id' => $this->branch->id, 'unit_price_minor' => 1, 'total_minor' => 1] as $key => $value) {
            $this->submit($this->payload([$key => $value]))->assertUnprocessable()->assertJsonValidationErrors($key);
        }$this->assertDatabaseCount('ingestion_events', 0);
    }

    public function test_api_rejects_unknown_products_invalid_quantities_and_ambiguous_dates(): void
    {
        foreach ([['sku' => 'MISSING'], ['quantity' => 0], ['quantity' => -1], ['quantity' => 1.5], ['sold_at' => '2026-01-01 12:00:00'], ['sold_at' => now()->addDay()->format('Y-m-d\TH:i:sP')], ['reference' => 'not-a-uuid']] as $change) {
            $this->submit($this->payload($change))->assertUnprocessable();
        }$this->assertDatabaseCount('jobs', 0);
    }

    public function test_status_endpoint_is_isolated_to_source(): void
    {
        $payload = $this->payload();
        $this->submit($payload);
        $otherToken = 'bf_'.bin2hex(random_bytes(32));
        $this->createSource($otherToken);
        $this->getJson('/api/v1/sales/'.$payload['reference'], $this->headers($otherToken))->assertNotFound();
        $this->getJson('/api/v1/sales/'.$payload['reference'], $this->headers())->assertOk()->assertJsonPath('data.status', 'queued');
        $this->postJson('/api/v1/sales', $payload, $this->headers($otherToken))->assertConflict();
    }

    public function test_revoked_key_cannot_submit_or_read_but_accepted_events_still_process(): void
    {
        $payload = $this->payload();
        $this->submit($payload);
        $this->source->update(['revoked_at' => now()]);
        $this->submit($this->payload())->assertUnauthorized();
        $this->getJson('/api/v1/sales/'.$payload['reference'], $this->headers())->assertUnauthorized();
        $this->assertSame('completed', $this->process($payload['reference'])->status);
    }

    public function test_source_creation_stores_only_key_hash_and_flashes_key_once(): void
    {
        $this->actingAs($this->owner)->post('/integrations/sources', ['name' => 'Checkout', 'branch_id' => $this->branch->id])->assertRedirect('/integrations');
        $token = session('new_source_token');
        $this->assertMatchesRegularExpression('/^bf_[a-f0-9]{64}$/', $token);
        $source = ApiSource::where('name', 'Checkout')->firstOrFail();
        $this->assertSame(hash('sha256', $token), $source->token_hash);
        $this->assertArrayNotHasKey('token_hash', $source->toArray());
        $this->assertDatabaseHas('audit_logs', ['action' => 'source.created']);
        $this->get('/integrations')->assertOk()->assertSee($token);
        $this->get('/integrations')->assertOk()->assertDontSee($token);
    }

    public function test_rotation_invalidates_old_key_and_new_key_keeps_source_history(): void
    {
        $this->actingAs($this->owner)->post('/integrations/sources/'.$this->source->id.'/rotate')->assertRedirect('/integrations');
        $newToken = session('new_source_token');
        $this->submit($this->payload())->assertUnauthorized();
        $this->postJson('/api/v1/sales', $this->payload(), $this->headers($newToken))->assertAccepted();
        $this->assertDatabaseHas('audit_logs', ['action' => 'source.rotated']);
    }

    public function test_owner_can_revoke_source(): void
    {
        $this->actingAs($this->owner)->post('/integrations/sources/'.$this->source->id.'/revoke')->assertRedirect('/integrations');
        $this->assertNotNull($this->source->fresh()->revoked_at);
        $this->submit($this->payload())->assertUnauthorized();
    }

    public function test_owner_can_retry_rejected_event_after_stock_is_received(): void
    {
        $payload = $this->payload(['quantity' => 21]);
        $this->submit($payload);
        $event = $this->process($payload['reference']);
        Stock::query()->increment('quantity', 10);
        $this->actingAs($this->owner)->post('/integrations/events/'.$event->id.'/retry')->assertRedirect('/integrations');
        $this->assertSame('queued', $event->fresh()->status);
        $this->assertSame('completed', $this->process($payload['reference'])->status);
        $this->assertDatabaseHas('stocks', ['quantity' => 9]);
    }

    public function test_completed_event_cannot_be_retried_manually(): void
    {
        $payload = $this->payload();
        $this->submit($payload);
        $event = $this->process($payload['reference']);
        $this->actingAs($this->owner)->post('/integrations/events/'.$event->id.'/retry')->assertConflict();
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_staff_cannot_manage_keys_monitor_or_retry(): void
    {
        $staff = User::factory()->create(['role' => 'staff', 'branch_id' => $this->branch->id]);
        $payload = $this->payload();
        $this->submit($payload);
        $event = IngestionEvent::firstOrFail();
        $this->actingAs($staff)->get('/integrations')->assertForbidden();
        $this->post('/integrations/sources', ['name' => 'Blocked', 'branch_id' => $this->branch->id])->assertForbidden();
        $this->post('/integrations/sources/'.$this->source->id.'/rotate')->assertForbidden();
        $this->post('/integrations/sources/'.$this->source->id.'/revoke')->assertForbidden();
        $this->post('/integrations/events/'.$event->id.'/retry')->assertForbidden();
        $this->withSession(['auth_version' => $staff->auth_version]);
        Livewire::actingAs($staff)->test(IngestionMonitor::class)->assertForbidden();
    }

    public function test_monitor_filters_and_polls_without_exposing_hashes(): void
    {
        $payload = $this->payload();
        $this->submit($payload);
        $this->withSession(['auth_version' => $this->owner->auth_version]);
        Livewire::actingAs($this->owner)->test(IngestionMonitor::class)->assertSee('Ingestion activity')->assertDontSee($this->source->token_hash)->set('status', 'failed')->assertSee('No events match these filters')->set('status', 'queued')->assertSee(substr($payload['reference'], 0, 8));
    }

    public function test_event_and_queue_row_rollback_together(): void
    {
        DB::beginTransaction();
        $this->submit($this->payload())->assertAccepted();
        $this->assertDatabaseCount('ingestion_events', 1);
        $this->assertDatabaseCount('jobs', 1);
        DB::rollBack();
        $this->assertDatabaseCount('ingestion_events', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_failed_job_marks_event_failed_and_can_be_reprocessed(): void
    {
        $payload = $this->payload();
        $this->submit($payload);
        $event = IngestionEvent::firstOrFail();
        (new ProcessIngestion($event->id))->failed(new \RuntimeException('Private failure detail'));
        $this->assertSame('failed', $event->fresh()->status);
        $this->assertStringNotContainsString('Private', $event->fresh()->error);
        $this->assertSame('completed', $this->process($payload['reference'])->status);
    }

    public function test_api_rate_limit_is_per_source(): void
    {
        $reference = (string) Str::uuid();
        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/api/v1/sales/'.$reference, $this->headers())->assertNotFound();
        }
        $this->submit($this->payload())->assertStatus(429)->assertHeader('Retry-After');
        $otherToken = 'bf_'.bin2hex(random_bytes(32));
        $this->createSource($otherToken);
        $this->postJson('/api/v1/sales', $this->payload(), $this->headers($otherToken))->assertAccepted();
    }

    public function test_oversized_body_and_form_submission_are_rejected(): void
    {
        $this->submit($this->payload(['extra' => str_repeat('x', 17000)]))->assertStatus(413);
        $this->post('/api/v1/sales', $this->payload(), array_merge($this->headers(), ['Accept' => 'application/json']))->assertStatus(415);
        $this->assertDatabaseCount('ingestion_events', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_rotating_a_revoked_source_is_forbidden(): void
    {
        $this->source->update(['revoked_at' => now()]);
        $hash = $this->source->token_hash;
        $this->actingAs($this->owner)->post('/integrations/sources/'.$this->source->id.'/rotate')->assertConflict();
        $this->assertSame($hash, $this->source->fresh()->token_hash);
    }
}
