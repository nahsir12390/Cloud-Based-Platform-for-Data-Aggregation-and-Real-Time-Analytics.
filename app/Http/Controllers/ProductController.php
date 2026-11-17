<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Product;
use App\Services\BranchAccess;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function edit(Request $request, Product $product)
    {
        BranchAccess::owner($request->user());

        return view('product-edit', ['page' => 'products', 'product' => $product]);
    }

    public function update(Request $request, Product $product)
    {
        BranchAccess::owner($request->user());
        $data = $request->validate(['name' => 'required|string|max:150', 'category' => 'required|string|max:80', 'price' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:100000000', 'regex:/^\d+(?:\.\d{1,2})?$/'], 'reorder_level' => 'required|integer|min:0|max:1000000', 'sku' => 'prohibited', 'price_minor' => 'prohibited']);
        DB::transaction(function () use ($request, $product, $data) {
            $product = Product::lockForUpdate()->findOrFail($product->id);
            $product->fill(['name' => $data['name'], 'category' => $data['category'], 'price_minor' => Money::minor((string) $data['price']), 'reorder_level' => (int) $data['reorder_level']]);
            if ($product->isDirty()) {
                $changes = implode(', ', array_keys($product->getDirty()));
                $product->save();
                AuditLog::create(['user_id' => $request->user()->id, 'action' => 'product.updated', 'description' => 'Updated product '.$product->sku.': '.$changes]);
            }
        });

        return redirect('/products')->with('success', 'Product updated. Previously recorded sale amounts are unchanged.');
    }
}
