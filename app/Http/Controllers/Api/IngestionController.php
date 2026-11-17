<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessIngestion;
use App\Models\ApiSource;
use App\Models\IngestionEvent;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IngestionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->isJson(), 415, 'Use Content-Type: application/json.');
        $data = $request->validate([
            'reference' => ['required', 'uuid'], 'sku' => ['required', 'string', 'max:60', 'exists:products,sku'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'sold_at' => ['required', 'string', 'max:35', 'date', 'before_or_equal:now', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/'],
            'branch_id' => ['prohibited'], 'unit_price_minor' => ['prohibited'], 'total_minor' => ['prohibited'],
        ]);
        $payload = ['reference' => strtolower($data['reference']), 'sku' => $data['sku'], 'quantity' => (int) $data['quantity'], 'sold_at' => Carbon::parse($data['sold_at'])->utc()->format('Y-m-d\TH:i:s\Z')];
        $fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $authenticated = $request->attributes->get('api_source');
        try {
            [$event,$created] = DB::transaction(function () use ($authenticated, $payload, $fingerprint) {
                // Serialize accepts/rotation for this source. Credential rotation is rechecked under the lock.
                $source = ApiSource::lockForUpdate()->findOrFail($authenticated->id);
                abort_if($source->revoked_at || ! hash_equals($source->token_hash, $authenticated->token_hash), 401, 'This API key is no longer active.');
                $existing = IngestionEvent::where('reference', $payload['reference'])->first();
                if ($existing) {
                    abort_unless($existing->api_source_id === $source->id && hash_equals($existing->fingerprint, $fingerprint), 409, 'Reference is already in use. Use the original payload for retries.');
                    $source->update(['last_seen_at' => now()]);

                    return [$existing, false];
                }
                $event = IngestionEvent::create(['id' => (string) Str::uuid(), 'reference' => $payload['reference'], 'api_source_id' => $source->id, 'branch_id' => $source->branch_id, 'user_id' => $source->user_id, 'product_id' => Product::where('sku', $payload['sku'])->firstOrFail()->id, 'payload' => $payload, 'fingerprint' => $fingerprint]);
                // This connection uses the application's database: the event and queue row commit together.
                ProcessIngestion::dispatch($event->id)->onConnection('ingestion')->beforeCommit();
                $source->update(['last_seen_at' => now()]);

                return [$event, true];
            }, 5);
        } catch (UniqueConstraintViolationException $exception) {
            // A simultaneous request from another source won the global reference.
            abort(409, 'Reference is already in use. Use a distinct reference for a different transaction.');
        }

        return response()->json(['data' => $this->representation($event), 'duplicate' => ! $created], $event->status === 'queued' ? 202 : 200);
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        $source = $request->attributes->get('api_source');
        $event = IngestionEvent::where('reference', strtolower($reference))->where('api_source_id', $source->id)->firstOrFail();

        return response()->json(['data' => $this->representation($event)]);
    }

    private function representation(IngestionEvent $event): array
    {
        return ['id' => $event->id, 'reference' => $event->reference, 'status' => $event->status, 'sale_id' => $event->sale_id, 'error' => $event->error, 'received_at' => $event->created_at->toIso8601String(), 'processed_at' => $event->processed_at?->toIso8601String(), 'status_url' => url('/api/v1/sales/'.$event->reference)];
    }
}
