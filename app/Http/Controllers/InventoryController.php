<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockOperation;
use App\Services\BranchAccess;
use App\Services\InventoryService;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function transfers(Request $request)
    {
        $request->validate(['branch_id' => 'nullable|integer|exists:branches,id']);
        if ($request->filled('branch_id')) {
            BranchAccess::authorize($request->user(), $request->integer('branch_id'));
        }
        $transfers = StockOperation::where('kind', 'transfer')->when($request->user()->role !== 'owner', fn ($q) => $q->where(fn ($q) => $q->where('branch_id', $request->user()->branch_id ?? -1)->orWhere('destination_branch_id', $request->user()->branch_id ?? -1)))->when($request->filled('branch_id'), fn ($q) => $q->where(fn ($q) => $q->where('branch_id', $request->integer('branch_id'))->orWhere('destination_branch_id', $request->integer('branch_id'))))->with(['branch', 'destination', 'product', 'user'])->latest()->orderByDesc('id')->paginate(20)->withQueryString();
        $branches = Branch::when($request->user()->role !== 'owner', fn ($q) => $q->where('id', $request->user()->branch_id ?? -1))->orderBy('name')->get();

        return view('transfers', ['page' => 'transfers', 'transfers' => $transfers, 'branches' => $branches, 'products' => Product::orderBy('name')->get()]);
    }

    public function transfer(Request $request, InventoryService $service)
    {
        $operation = $service->transfer($request->user(), $request->all());

        return redirect('/inventory/transfers')->with('success', 'Stock transfer recorded: '.$operation->reference);
    }

    public function editAdjustment(Request $request, Stock $stock)
    {
        BranchAccess::owner($request->user());
        $stock->load(['branch', 'product']);
        $history = StockOperation::where('kind', 'adjustment')->where('branch_id', $stock->branch_id)->where('product_id', $stock->product_id)->with('user')->latest()->paginate(10);

        return view('stock-adjustment', ['page' => 'inventory', 'stock' => $stock, 'history' => $history]);
    }

    public function adjust(Request $request, Stock $stock, InventoryService $service)
    {
        $operation = $service->adjust($request->user(), $stock, $request->all());

        return redirect('/inventory/'.$stock->id.'/adjust')->with('success','Stock adjustment recorded: '.$operation->reference);
    }
}
