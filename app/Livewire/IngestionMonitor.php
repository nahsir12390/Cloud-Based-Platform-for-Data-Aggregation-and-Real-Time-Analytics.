<?php

namespace App\Livewire;

use App\Models\ApiSource;
use App\Models\Branch;
use App\Models\IngestionEvent;
use App\Services\BranchAccess;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class IngestionMonitor extends Component
{
    use WithPagination;

    public string $branch = '';

    public string $status = '';

    public function updatedBranch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        BranchAccess::owner(Auth::user());
        $this->validate(['branch' => 'nullable|integer|exists:branches,id', 'status' => 'nullable|in:queued,completed,rejected,failed']);
        $base = fn () => IngestionEvent::query()->when($this->branch !== '', fn ($q) => $q->where('branch_id', (int) $this->branch));
        $counts = $base()->selectRaw('status, COUNT(*) as count')->groupBy('status')->pluck('count', 'status');
        $events = $base()->when($this->status !== '', fn ($q) => $q->where('status', $this->status))->with(['source', 'branch'])->latest()->orderByDesc('id')->paginate(15);
        $sources = ApiSource::with('branch')->withCount('events')->when($this->branch !== '', fn ($q) => $q->where('branch_id', (int) $this->branch))->latest()->get();
        $branches = Branch::orderBy('name')->get();
        $oldest = $base()->where('status', 'queued')->oldest()->first();

        return view('livewire.ingestion-monitor', compact('counts', 'events', 'sources', 'branches', 'oldest'));
    }
}
