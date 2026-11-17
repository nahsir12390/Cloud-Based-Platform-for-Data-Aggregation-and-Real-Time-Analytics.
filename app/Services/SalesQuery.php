<?php

namespace App\Services;

use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class SalesQuery
{
    public function filtered(Request $request): Builder
    {
        $toRules = ['nullable', 'date_format:Y-m-d'];
        if ($request->filled('from')) {
            $toRules[] = 'after_or_equal:from';
        }
        $request->validate(['q' => 'nullable|string|max:100', 'branch_id' => 'nullable|integer|exists:branches,id', 'product_id' => 'nullable|integer|exists:products,id', 'from' => 'nullable|date_format:Y-m-d', 'to' => $toRules, 'status' => 'nullable|in:completed,returned', 'source' => 'nullable|in:web,csv,api,demo', 'sort' => 'nullable|in:newest,oldest,amount_desc,amount_asc']);
        if ($request->filled('branch_id')) {
            BranchAccess::authorize($request->user(), $request->integer('branch_id'));
        }
        $query = BranchAccess::scope(Sale::query(), $request->user())
            ->when($request->filled('branch_id'), fn ($q) => $q->where('sales.branch_id', $request->integer('branch_id')))
            ->when($request->filled('product_id'), fn ($q) => $q->where('sales.product_id', $request->integer('product_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('sales.status', $request->input('status')))
            ->when($request->filled('source'), fn ($q) => $q->where('sales.source', $request->input('source')));
        if ($request->filled('from')) {
            $query->where('sales.sold_at', '>=', Carbon::createFromFormat('!Y-m-d', $request->input('from'), config('app.timezone')));
        }
        if ($request->filled('to')) {
            $query->where('sales.sold_at', '<', Carbon::createFromFormat('!Y-m-d', $request->input('to'), config('app.timezone'))->addDay());
        }
        if ($request->filled('q')) {
            $pattern = '%'.mb_strtolower($request->input('q')).'%';
            $cast = in_array($query->getConnection()->getDriverName(), ['mysql', 'mariadb']) ? 'CHAR' : 'TEXT';
            $query->where(fn ($q) => $q->whereRaw("LOWER(CAST(sales.reference AS {$cast})) LIKE ?", [$pattern])->orWhereHas('product', fn ($q) => $q->whereLike('name', $pattern)->orWhereLike('sku', $pattern)));
        }

        return $query;
    }

    public function ordered(Builder $query, Request $request): Builder
    {
        return match ($request->input('sort')) {
            'oldest' => $query->orderBy('sold_at')->orderBy('id'),
            'amount_desc' => $query->orderByDesc('total_minor')->orderByDesc('id'),
            'amount_asc' => $query->orderBy('total_minor')->orderBy('id'),
            default => $query->orderByDesc('sold_at')->orderByDesc('id'),
        };
    }

    public function summary(Builder $query): array
    {
        $result = (clone $query)->selectRaw("COUNT(*) AS matched_sales, COALESCE(SUM(CASE WHEN sales.status = 'completed' THEN sales.total_minor ELSE 0 END),0) AS revenue_minor, COALESCE(SUM(CASE WHEN sales.status = 'completed' THEN sales.quantity ELSE 0 END),0) AS units, COALESCE(SUM(CASE WHEN sales.status = 'returned' THEN sales.total_minor ELSE 0 END),0) AS returned_minor")->first();

        return ['matched_sales' => (int) $result->matched_sales, 'revenue_minor' => (int) $result->revenue_minor, 'units' => (int) $result->units, 'returned_minor' => (int) $result->returned_minor];
    }

    public function parameters(Request $request): array
    {
        return array_filter($request->only(['q', 'branch_id', 'product_id', 'from', 'to', 'status', 'source', 'sort']), fn ($value) => $value !== null && $value !== '');
    }
}
