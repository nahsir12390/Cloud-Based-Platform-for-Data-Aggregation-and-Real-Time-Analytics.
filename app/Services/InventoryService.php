<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\StockOperation;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function transfer(User $user, array $data): StockOperation
    {
        BranchAccess::owner($user);
        $data = Validator::make($data, ['reference' => 'required|uuid', 'branch_id' => 'required|integer|exists:branches,id', 'destination_branch_id' => 'required|integer|exists:branches,id|different:branch_id', 'product_id' => 'required|integer|exists:products,id', 'quantity' => 'required|integer|min:1|max:1000000', 'note' => 'required|string|min:5|max:200'])->validate();
        $payload = ['kind' => 'transfer', 'branch_id' => (int) $data['branch_id'], 'destination_branch_id' => (int) $data['destination_branch_id'], 'product_id' => (int) $data['product_id'], 'quantity' => (int) $data['quantity'], 'note' => trim($data['note'])];

        return $this->transaction(function () use ($user, $data, $payload) {
            // Acquire both stock rows in a stable order, including transfers in opposite directions.
            $stocks = Stock::where('product_id', $payload['product_id'])->whereIn('branch_id', [$payload['branch_id'], $payload['destination_branch_id']])->orderBy('id')->lockForUpdate()->get()->keyBy('branch_id');
            if ($existing = $this->existing($data['reference'], $payload)) {
                return $existing;
            }
            $from = $stocks->get($payload['branch_id']);
            $to = $stocks->get($payload['destination_branch_id']);
            if (! $from || ! $to) {
                throw ValidationException::withMessages(['product_id' => 'Stock balances must exist at both branches before transferring this product.']);
            }
            if ($from->quantity < $payload['quantity']) {
                throw ValidationException::withMessages(['quantity' => 'The sending branch does not have enough stock.']);
            }
            if ($to->quantity + $payload['quantity'] > 1000000000) {
                throw ValidationException::withMessages(['quantity' => 'The receiving branch stock balance would exceed the supported limit.']);
            }
            $operation = StockOperation::create($payload + ['reference' => strtolower($data['reference']), 'user_id' => $user->id, 'reason' => 'branch_transfer', 'fingerprint' => $this->fingerprint($payload)]);
            $from->decrement('quantity', $payload['quantity']);
            $to->increment('quantity', $payload['quantity']);
            $this->movement($user, $operation, $from->branch_id, -$payload['quantity'], 'transfer_out');
            $this->movement($user, $operation, $to->branch_id, $payload['quantity'], 'transfer_in');
            $this->audit($user, $from->branch_id, 'stock.transferred_out', 'Transferred '.$payload['quantity'].' units to branch #'.$to->branch_id.'; reference '.$operation->reference);
            $this->audit($user, $to->branch_id, 'stock.transferred_in', 'Received '.$payload['quantity'].' units from branch #'.$from->branch_id.'; reference '.$operation->reference);

            return $operation;
        });
    }

    public function adjust(User $user, Stock $stock, array $data): StockOperation
    {
        BranchAccess::owner($user);
        $data = Validator::make($data, ['reference' => 'required|uuid', 'expected_quantity' => 'required|integer|min:0|max:1000000000', 'counted_quantity' => 'required|integer|min:0|max:1000000000', 'reason' => 'required|in:stock_count,damaged,expired,correction', 'note' => 'required|string|min:5|max:200'])->validate();
        $payload = ['kind' => 'adjustment', 'branch_id' => $stock->branch_id, 'product_id' => $stock->product_id, 'expected_quantity' => (int) $data['expected_quantity'], 'resulting_quantity' => (int) $data['counted_quantity'], 'reason' => $data['reason'], 'note' => trim($data['note'])];

        return $this->transaction(function () use ($user, $stock, $data, $payload) {
            $balance = Stock::lockForUpdate()->findOrFail($stock->id);
            if ($existing = $this->existing($data['reference'], $payload)) {
                return $existing;
            }
            if ($balance->quantity !== $payload['expected_quantity']) {
                throw ValidationException::withMessages(['counted_quantity' => 'Stock changed after this form was opened. Refresh the page, check the latest balance and enter the count again.']);
            }
            $difference = $payload['resulting_quantity'] - $balance->quantity;
            if ($difference === 0) {
                throw ValidationException::withMessages(['counted_quantity' => 'The counted quantity matches the recorded balance. No adjustment is needed.']);
            }
            if (in_array($payload['reason'], ['damaged', 'expired']) && $difference > 0) {
                throw ValidationException::withMessages(['reason' => 'Damaged or expired stock must reduce the available quantity.']);
            }
            $operation = StockOperation::create($payload + ['reference' => strtolower($data['reference']), 'user_id' => $user->id, 'quantity' => $difference, 'fingerprint' => $this->fingerprint($payload)]);
            $balance->update(['quantity' => $payload['resulting_quantity']]);
            $this->movement($user, $operation, $balance->branch_id, $difference, 'adjustment');
            $this->audit($user, $balance->branch_id, 'stock.adjusted', 'Adjusted product #'.$balance->product_id.' from '.$payload['expected_quantity'].' to '.$payload['resulting_quantity'].' units ('.$payload['reason'].'); reference '.$operation->reference);

            return $operation;
        });
    }

    private function existing(string $reference, array $payload): ?StockOperation
    {
        $operation = StockOperation::where('reference', strtolower($reference))->first();
        if ($operation && ! hash_equals($operation->fingerprint, $this->fingerprint($payload))) {
            throw ValidationException::withMessages(['reference' => 'This operation reference was already used with different details. Open a fresh form for a different operation.']);
        }

        return $operation;
    }

    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function transaction(callable $operation): StockOperation
    {
        try {
            return DB::transaction($operation, 5);
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(['reference' => 'This operation reference is already in use. Reload the operation history before trying again.']);
        }
    }

    private function movement(User $user, StockOperation $operation, int $branch, int $quantity, string $type): void
    {
        StockMovement::create(['stock_operation_id' => $operation->id, 'branch_id' => $branch, 'product_id' => $operation->product_id, 'user_id' => $user->id, 'quantity' => $quantity, 'type' => $type, 'note' => $operation->note]);
    }

    private function audit(User $user, int $branch, string $action, string $description): void
    {
        AuditLog::create(['user_id' => $user->id, 'branch_id' => $branch, 'action' => $action, 'description' => $description]);
    }
}
