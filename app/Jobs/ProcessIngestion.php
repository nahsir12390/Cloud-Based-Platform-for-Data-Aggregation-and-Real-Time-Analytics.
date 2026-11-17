<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\IngestionEvent;
use App\Services\SaleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProcessIngestion implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public string $eventId) {}

    public function backoff(): array
    {
        return [5, 30];
    }

    public function handle(SaleService $service): void
    {
        DB::transaction(function () use ($service) {
            $event = IngestionEvent::lockForUpdate()->findOrFail($this->eventId);
            if (in_array($event->status, ['completed', 'rejected'])) {
                return;
            }
            try {
                $sale = $service->record($event->user, ['reference' => $event->reference, 'branch_id' => $event->branch_id, 'product_id' => $event->product_id, 'quantity' => $event->payload['quantity'], 'sold_at' => $event->payload['sold_at']], 'api');
                $event->update(['status' => 'completed', 'sale_id' => $sale->id, 'error' => null, 'processed_at' => now()]);
            } catch (ValidationException $exception) {
                $error = implode(' ', $exception->validator->errors()->all());
                $event->update(['status' => 'rejected', 'error' => $error, 'processed_at' => now()]);
                AuditLog::create(['user_id' => $event->user_id, 'branch_id' => $event->branch_id, 'action' => 'ingestion.rejected', 'description' => 'Rejected API sale '.$event->reference]);
            }
        }, 5);
    }

    public function failed(?Throwable $exception): void
    {
        IngestionEvent::whereKey($this->eventId)->whereNotIn('status', ['completed', 'rejected'])->update(['status' => 'failed', 'error' => 'Processing failed after retries. An owner can retry this event.', 'processed_at' => now()]);
    }
}
