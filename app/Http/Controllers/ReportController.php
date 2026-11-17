<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Services\BranchAccess;
use App\Services\SalesQuery;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function index(Request $request, SalesQuery $queries)
    {
        $base = $queries->filtered($request);
        $summary = $queries->summary($base);
        $byBranch = (clone $base)->where('sales.status', 'completed')->join('branches', 'branches.id', '=', 'sales.branch_id')->selectRaw('branches.name, COUNT(*) as transactions, SUM(sales.quantity) as units, SUM(sales.total_minor) as revenue_minor')->groupBy('branches.id', 'branches.name')->orderByDesc('revenue_minor')->get();
        $byProduct = (clone $base)->where('sales.status', 'completed')->join('products', 'products.id', '=', 'sales.product_id')->selectRaw('products.name, products.sku, SUM(sales.quantity) as units, SUM(sales.total_minor) as revenue_minor')->groupBy('products.id', 'products.name', 'products.sku')->orderByDesc('revenue_minor')->limit(10)->get();

        return view('reports', ['page' => 'reports', 'summary' => $summary, 'byBranch' => $byBranch, 'byProduct' => $byProduct, 'branches' => $this->branches($request), 'products' => Product::orderBy('name')->get(), 'exportParameters' => $queries->parameters($request)]);
    }

    public function sale(Request $request, Sale $sale)
    {
        BranchAccess::authorize($request->user(), $sale->branch_id);
        $sale->load(['branch', 'product', 'user']);
        $movements = StockMovement::where('sale_id', $sale->id)->with('user')->orderBy('id')->get();

        return view('sale-detail', ['page' => 'sales', 'sale' => $sale, 'movements' => $movements]);
    }

    private function branches(Request $request)
    {
        return Branch::when($request->user()->role !== 'owner', fn ($q) => $q->where('id', $request->user()->branch_id ?? -1))->orderBy('name')->get();
    }
}
