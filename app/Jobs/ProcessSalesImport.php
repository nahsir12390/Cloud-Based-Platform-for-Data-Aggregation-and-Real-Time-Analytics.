<?php

namespace App\Jobs;

use App\Models\Import;
use App\Models\Product;
use App\Models\User;
use App\Services\SaleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProcessSalesImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $importId) {}

    public function handle(SaleService $service): void
    {
        $import = Import::findOrFail($this->importId);
        if ($import->status === 'completed') {
            return;
        }
        $import->update(['status' => 'processing']);
        $user = User::findOrFail($import->user_id);
        $usesDurableContent = $import->content !== null;
        if ($usesDurableContent) {
            $f = fopen('php://temp', 'w+');
            fwrite($f, $import->content);
            rewind($f);
        } else {
            $f = fopen(Storage::path($import->path), 'r');
        }
        $processed = 0;
        $rejected = 0;
        $errors = [];
        try {
            $header = fgetcsv($f, 0, ',', '"', '');
            if ($header) {
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            }
            if ($header !== ['reference', 'sku', 'quantity', 'sold_at']) {
                $import->update(['status' => 'failed', 'errors' => ['Header must be: reference,sku,quantity,sold_at']]);

                return;
            }
            $rowNumber = 1;
            while (($row = fgetcsv($f, 0, ',', '"', '')) !== false) {
                $rowNumber++;
                if ($row === [null]) {
                    continue;
                }
                if ($rowNumber > 5001) {
                    $errors[] = 'Maximum 5,000 rows per import.';
                    $rejected++;
                    break;
                }
                try {
                    if (count($row) !== 4) {
                        throw ValidationException::withMessages(['row' => 'Expected four columns.']);
                    }
                    $data = array_combine($header, $row);
                    Validator::make($data, ['reference' => 'required|uuid', 'sku' => 'required|string', 'quantity' => 'required|integer|min:1|max:1000000', 'sold_at' => 'required|date|before_or_equal:now'])->validate();
                    $product = Product::where('sku', $data['sku'])->first();
                    if (! $product) {
                        throw ValidationException::withMessages(['sku' => 'Unknown SKU.']);
                    }
                    $service->record($user, ['reference' => $data['reference'], 'branch_id' => $import->branch_id, 'product_id' => $product->id, 'quantity' => (int) $data['quantity'], 'sold_at' => $data['sold_at']], 'csv');
                    $processed++;
                } catch (ValidationException $e) {
                    $rejected++;
                    if (count($errors) < 100) {
                        $errors[] = 'Row '.$rowNumber.': '.implode(' ', $e->validator->errors()->all());
                    }
                }
            }
            $import->update(['status' => 'completed', 'processed' => $processed, 'rejected' => $rejected, 'errors' => $errors, 'content' => null]);
            if ($usesDurableContent) {
                Storage::delete($import->path);
            }
        } finally {
            fclose($f);
        }
    }

    public function failed(?Throwable $e): void
    {
        Import::whereKey($this->importId)->update(['status' => 'failed', 'errors' => ['Processing failed. Check the application log, then retry the failed queue job.']]);
    }
}
