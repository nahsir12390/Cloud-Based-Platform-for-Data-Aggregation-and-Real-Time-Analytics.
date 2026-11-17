<?php

namespace App\Livewire;

use App\Models\Branch;
use App\Models\Sale;
use App\Models\Stock;
use App\Services\BranchAccess;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Dashboard extends Component
{
    public string $branch = '';

    public string $period = '7';

    public function render()
    {
        $this->validate(['branch' => 'nullable|integer|exists:branches,id', 'period' => 'required|in:7,30,90']);
        if ($this->branch !== '') {
            BranchAccess::authorize(Auth::user(), (int) $this->branch);
        }
        $start = now()->subDays((int) $this->period - 1)->startOfDay();
        $base = fn () => BranchAccess::scope(Sale::query(), Auth::user())->where('status', 'completed')->where('sold_at', '>=', $start)->where('sold_at', '<=', now())->when($this->branch !== '', fn ($q) => $q->where('branch_id', (int) $this->branch));
        $revenue = (int) $base()->sum('total_minor');
        $transactions = $base()->count();
        $units = (int) $base()->sum('quantity');
        $low = BranchAccess::scope(Stock::query(), Auth::user())->join('products', 'products.id', '=', 'stocks.product_id')->whereColumn('stocks.quantity', '<=', 'products.reorder_level')->when($this->branch !== '', fn ($q) => $q->where('stocks.branch_id', (int) $this->branch))->count();
        $daily = $base()->selectRaw('DATE(sold_at) as day, SUM(total_minor) as total')->groupByRaw('DATE(sold_at)')->pluck('total', 'day');
        $trend = collect(range(0, (int) $this->period - 1))->map(function ($i) use ($start, $daily) {
            $date = $start->copy()->addDays($i);

            return ['label' => $date->format('d M'), 'value' => (int) ($daily[$date->format('Y-m-d')] ?? 0)];
        });
        $max = max(1, $trend->max('value'));
        $leaders = $base()->join('branches', 'branches.id', '=', 'sales.branch_id')->selectRaw('branches.name, SUM(total_minor) as total, COUNT(*) as transactions')->groupBy('branches.id', 'branches.name')->orderByDesc('total')->get();
        $top = $base()->join('products', 'products.id', '=', 'sales.product_id')->selectRaw('products.name, products.category, SUM(quantity) as units, SUM(total_minor) as total')->groupBy('products.id', 'products.name', 'products.category')->orderByDesc('total')->limit(5)->get();
        $recent = $base()->with(['branch', 'product'])->latest('sold_at')->limit(6)->get();
        $branches = Branch::when(Auth::user()->role !== 'owner', fn ($q) => $q->where('id', Auth::user()->branch_id ?? -1))->get();

        return view('livewire.dashboard',compact('revenue','transactions','units','low','trend','max','leaders','top','recent','branches'));
    }
}
