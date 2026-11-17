<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessSalesImport;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Import;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use App\Rules\PasswordInput;
use App\Services\BranchAccess;
use App\Services\SaleService;
use App\Services\SalesQuery;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class WorkspaceController extends Controller
{
    public function index(Request $r, string $page = 'dashboard')
    {
        abort_unless(in_array($page, ['dashboard', 'sales', 'inventory', 'branches', 'products', 'imports', 'activity', 'team']), 404);
        if (in_array($page, ['branches', 'products', 'team'])) {
            BranchAccess::owner($r->user());
        }
        $branches = Branch::when($r->user()->role !== 'owner', fn ($q) => $q->where('id', $r->user()->branch_id ?? -1))->get();
        $products = Product::orderBy('name')->get();
        $sales = collect();
        $salesSummary = [];
        $exportParameters = [];
        if ($page === 'sales') {
            $queries = app(SalesQuery::class);
            $base = $queries->filtered($r);
            $salesSummary = $queries->summary($base);
            $exportParameters = $queries->parameters($r);
            $sales = $queries->ordered($base, $r)->with(['branch', 'product', 'user'])->paginate(20)->withQueryString();
        }
        $stocks = collect();
        if ($page === 'inventory') {
            $r->validate(['q' => 'nullable|string|max:100', 'branch_id' => 'nullable|integer|exists:branches,id', 'stock_status' => 'nullable|in:low,out,healthy']);
            if ($r->filled('branch_id')) {
                BranchAccess::authorize($r->user(), $r->integer('branch_id'));
            }
            $stocks = BranchAccess::scope(Stock::query(), $r->user())
                ->with(['branch', 'product'])
                ->when($r->filled('branch_id'), fn ($q) => $q->where('branch_id', $r->integer('branch_id')))
                ->when($r->filled('q'), fn ($q) => $q->whereHas('product', fn ($q) => $q->where(fn ($q) => $q->whereLike('name', '%'.$r->input('q').'%')->orWhereLike('sku', '%'.$r->input('q').'%'))))
                ->when($r->input('stock_status') === 'low', fn ($q) => $q->whereHas('product', fn ($q) => $q->whereColumn('stocks.quantity', '<=', 'products.reorder_level')))
                ->when($r->input('stock_status') === 'out', fn ($q) => $q->where('quantity', 0))
                ->when($r->input('stock_status') === 'healthy', fn ($q) => $q->whereHas('product', fn ($q) => $q->whereColumn('stocks.quantity', '>', 'products.reorder_level')))
                ->orderBy('branch_id')->orderBy('id')->paginate(20)->withQueryString();
        }
        $imports = $page === 'imports' ? BranchAccess::scope(Import::query(), $r->user())->select(['id', 'user_id', 'branch_id', 'filename', 'path', 'status', 'processed', 'rejected', 'errors', 'created_at', 'updated_at'])->with('branch')->latest()->paginate(10) : collect();
        $activity = $page === 'activity' ? BranchAccess::scope(AuditLog::query(), $r->user())->with(['user', 'branch'])->latest()->paginate(30) : collect();
        $movements = $page === 'inventory' ? BranchAccess::scope(StockMovement::query(), $r->user())->with(['user', 'branch', 'product'])->latest()->limit(15)->get() : collect();
        $users = $page === 'team' ? User::orderBy('name')->get() : collect();

        return view('workspace', compact('page', 'branches', 'products', 'sales', 'stocks', 'imports', 'activity', 'movements', 'users', 'salesSummary', 'exportParameters'));
    }

    public function branch(Request $r)
    {
        BranchAccess::owner($r->user());
        $data = $r->validate(['name' => 'required|string|max:100|unique:branches', 'location' => 'required|string|max:200']);
        DB::transaction(function () use ($data, $r) {
            $branch = Branch::create($data);
            foreach (Product::all() as $p) {
                Stock::create(['branch_id' => $branch->id, 'product_id' => $p->id, 'quantity' => 0]);
            }
            $this->audit($r, $branch->id, 'branch.created', 'Created branch '.$branch->name);
        });

        return back()->with('success', 'Branch created. Receive stock to start selling.');
    }

    public function product(Request $r)
    {
        BranchAccess::owner($r->user());
        $r->merge(['sku' => strtoupper((string) $r->input('sku'))]);
        $data = $r->validate(['name' => 'required|string|max:150', 'sku' => 'required|alpha_dash|max:60|unique:products', 'category' => 'required|string|max:80', 'price' => ['required', 'decimal:0,2', 'numeric', 'min:0.01', 'max:100000000', 'regex:/^\d+(?:\.\d{1,2})?$/'], 'reorder_level' => 'required|integer|min:0|max:1000000']);
        DB::transaction(function () use ($data, $r) {
            $p = Product::create(['name' => $data['name'], 'sku' => strtoupper($data['sku']), 'category' => $data['category'], 'price_minor' => Money::minor((string) $data['price']), 'reorder_level' => $data['reorder_level']]);
            foreach (Branch::all() as $b) {
                Stock::create(['branch_id' => $b->id, 'product_id' => $p->id, 'quantity' => 0]);
            }
            $this->audit($r, null, 'product.created', 'Created product '.$p->sku);
        });

        return back()->with('success', 'Product added to the catalog.');
    }

    public function stock(Request $r)
    {
        BranchAccess::owner($r->user());
        $data = $r->validate(['branch_id' => 'required|exists:branches,id', 'product_id' => 'required|exists:products,id', 'quantity' => 'required|integer|min:1|max:1000000', 'note' => 'required|string|max:200']);
        DB::transaction(function () use ($data, $r) {
            $stock = Stock::where('branch_id', $data['branch_id'])->where('product_id', $data['product_id'])->lockForUpdate()->firstOrFail();
            $stock->increment('quantity', $data['quantity']);
            StockMovement::create($data + ['user_id' => $r->user()->id, 'type' => 'receipt']);
            $this->audit($r, (int) $data['branch_id'], 'stock.received', 'Received '.$data['quantity'].' units of product #'.$data['product_id'].': '.$data['note']);
        }, 5);

        return back()->with('success', 'Stock received and recorded in the ledger.');
    }

    public function sale(Request $r, SaleService $service)
    {
        $data = $r->validate(['branch_id' => 'required|exists:branches,id', 'product_id' => 'required|exists:products,id', 'quantity' => 'required|integer|min:1|max:1000000', 'reference' => 'required|uuid']);
        $sale = $service->record($r->user(), $data);

        return back()->with('success', 'Sale recorded: '.$sale->reference);
    }

    public function reverse(Request $r, Sale $sale, SaleService $service)
    {
        $service->reverse($r->user(), $sale);

        return back()->with('success', 'Sale returned. Stock and analytics have been updated.');
    }

    public function import(Request $r)
    {
        $data = $r->validate(['branch_id' => 'required|exists:branches,id', 'file' => 'required|file|mimes:csv,txt|max:2048']);
        BranchAccess::authorize($r->user(), (int) $data['branch_id']);
        $file = $r->file('file');
        $content = $file->get();
        if (! mb_check_encoding($content, 'UTF-8')) {
            throw ValidationException::withMessages(['file' => 'Upload a UTF-8 encoded CSV file.']);
        }
        $path = $file->store('imports');
        $import = DB::transaction(function () use ($r, $file, $path, $data, $content) {
            $import = Import::create(['user_id' => $r->user()->id, 'branch_id' => $data['branch_id'], 'filename' => $file->getClientOriginalName(), 'path' => $path, 'content' => $content]);
            ProcessSalesImport::dispatch($import->id)->onConnection('ingestion')->beforeCommit();
            $this->audit($r, (int) $data['branch_id'], 'import.queued', 'Queued CSV import #'.$import->id);

            return $import;
        });

        return back()->with('success', 'Import #'.$import->id.' queued. Refresh this page to check the processing result.');
    }

    public function team(Request $r)
    {
        BranchAccess::owner($r->user());
        $data = $r->validate(['name' => 'required|string|max:100', 'email' => 'required|email|max:200|unique:users', 'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed', new PasswordInput], 'branch_id' => 'required|exists:branches,id']);
        DB::transaction(function () use ($r, $data) {
            User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($data['password']), 'role' => 'staff', 'branch_id' => $data['branch_id']]);
            $this->audit($r, (int) $data['branch_id'], 'user.created', 'Created staff account '.$data['email']);
        });

        return back()->with('success', 'Staff account created.');
    }

    public function export(Request $r)
    {
        $queries = app(SalesQuery::class);
        $q = $queries->ordered($queries->filtered($r), $r)->with(['branch', 'product']);

        return response()->streamDownload(function () use ($q) {
            $f = fopen('php://output', 'w');
            fputcsv($f, ['reference', 'branch', 'sku', 'quantity', 'unit_price_ngn', 'total_ngn', 'status', 'sold_at'], ',', '"', '');
            foreach ($q->lazy(500) as $s) {
                $safe = fn ($v) => preg_match('/^(?:[\s]*[=+@-]|[\t\r\n])/u', (string) $v) ? "'".$v : $v;
                fputcsv($f, array_map($safe, [$s->reference, $s->branch->name, $s->product->sku, $s->quantity, number_format($s->unit_price_minor / 100, 2, '.', ''), number_format($s->total_minor / 100, 2, '.', ''), $s->status, $s->sold_at->toIso8601String()]), ',', '"', '');
            }fclose($f);
        }, 'branchflow-sales.csv', ['Content-Type' => 'text/csv']);
    }

    private function audit(Request $r, ?int $branch, string $action, string $description): void
    {
        AuditLog::create(['user_id' => $r->user()->id, 'branch_id' => $branch, 'action' => $action, 'description' => $description]);
    }
}
