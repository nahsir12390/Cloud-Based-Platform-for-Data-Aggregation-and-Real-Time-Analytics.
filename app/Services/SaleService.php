<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleService
{
    public function record(User $user, array $data, string $source = 'web'): Sale
    {
        BranchAccess::authorize($user, (int) $data['branch_id']);
        $data['reference'] = strtolower($data['reference']);

        return DB::transaction(function () use ($user, $data, $source) {
            // Lock the branch/product balance before checking idempotency or changing stock.
            $stock = Stock::where('branch_id', $data['branch_id'])->where('product_id', $data['product_id'])->lockForUpdate()->first();
            $existing = Sale::where('reference', $data['reference'])->first();
            if ($existing) {
                if ($existing->branch_id != $data['branch_id'] || $existing->product_id != $data['product_id'] || $existing->quantity != $data['quantity']) {
                    throw ValidationException::withMessages(['reference' => 'This reference belongs to a different transaction.']);
                }

                if (isset($data['sold_at']) && Carbon::parse($data['sold_at'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s') !== $existing->sold_at->format('Y-m-d H:i:s')) {
                    throw ValidationException::withMessages(['reference' => 'This reference belongs to a transaction with a different sale timestamp.']);
                }

                return $existing;
            }
            if (! $stock || $stock->quantity < $data['quantity']) {
                throw ValidationException::withMessages(['quantity' => 'There is not enough stock at this branch.']);
            }
            $product = Product::findOrFail($data['product_id']);
            $stock->decrement('quantity', (int) $data['quantity']);
            $sale = Sale::create(['reference' => $data['reference'], 'branch_id' => $data['branch_id'], 'product_id' => $product->id, 'user_id' => $user->id, 'quantity' => $data['quantity'], 'unit_price_minor' => $product->price_minor, 'total_minor' => $product->price_minor * (int) $data['quantity'], 'source' => $source, 'sold_at' => isset($data['sold_at']) ? Carbon::parse($data['sold_at'])->setTimezone(config('app.timezone')) : now()]);
            StockMovement::create(['branch_id' => $sale->branch_id, 'product_id' => $product->id, 'user_id' => $user->id, 'sale_id' => $sale->id, 'quantity' => -$sale->quantity, 'type' => 'sale', 'note' => 'Sale '.$sale->reference]);
            AuditLog::create(['user_id' => $user->id, 'branch_id' => $sale->branch_id, 'action' => 'sale.created', 'description' => 'Recorded sale '.$sale->reference]);

            return $sale;
        }, 5);
    }

    public function reverse(User $user, Sale $sale): void
    {
        BranchAccess::owner($user);
        DB::transaction(function () use ($user, $sale) {
            $stock = Stock::where('branch_id', $sale->branch_id)->where('product_id', $sale->product_id)->lockForUpdate()->firstOrFail();
            $locked = Sale::lockForUpdate()->findOrFail($sale->id);
            if ($locked->status === 'returned') {
                return;
            }
            $stock->increment('quantity', $locked->quantity);
            $locked->update(['status' => 'returned']);
            StockMovement::create(['branch_id' => $sale->branch_id, 'product_id' => $sale->product_id, 'user_id' => $user->id, 'sale_id' => $sale->id, 'quantity' => $sale->quantity, 'type' => 'return', 'note' => 'Full return of '.$sale->reference]);
            AuditLog::create(['user_id' => $user->id, 'branch_id' => $sale->branch_id, 'action' => 'sale.returned', 'description' => 'Returned sale '.$sale->reference]);
        }, 5);
    }
}
