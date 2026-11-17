<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class BranchAccess
{
    public static function authorize(User $user, int $branchId): void
    {
        abort_unless($user->is_active && ($user->role === 'owner' || $user->branch_id === $branchId), 403);
    }

    public static function scope(Builder $query, User $user): Builder
    {
        if (! $user->is_active) {
            return $query->whereRaw('1 = 0');
        }

        return $user->role === 'owner' ? $query : $query->where('branch_id', $user->branch_id ?? -1);
    }

    public static function owner(User $user): void
    {
        abort_unless($user->is_active && $user->role === 'owner', 403);
    }
}
