<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessIngestion;
use App\Models\ApiSource;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\IngestionEvent;
use App\Services\BranchAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class IntegrationController extends Controller
{
    public function index(Request $request)
    {
        BranchAccess::owner($request->user());

        return response()->view('integrations', ['page' => 'integrations', 'branches' => Branch::orderBy('name')->get()])->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request)
    {
        BranchAccess::owner($request->user());
        $data = $request->validate(['branch_id' => ['required', 'exists:branches,id'], 'name' => ['required', 'string', 'max:100', Rule::unique('api_sources')->where('branch_id', $request->input('branch_id'))]]);
        $token = 'bf_'.bin2hex(random_bytes(32));
        DB::transaction(function () use ($request, $data, $token) {
            $source = ApiSource::create($data + ['user_id' => $request->user()->id, 'token_hash' => hash('sha256', $token), 'token_prefix' => substr($token, 0, 11)]);
            $this->audit($request, $source->branch_id, 'source.created', 'Created API source '.$source->name);
        });

        return redirect('/integrations')->with('new_source_token', $token)->with('success', 'Source created. Save its API key now.');
    }

    public function rotate(Request $request, ApiSource $source)
    {
        BranchAccess::owner($request->user());
        $token = 'bf_'.bin2hex(random_bytes(32));
        DB::transaction(function () use ($request, $source, $token) {
            $source = ApiSource::lockForUpdate()->findOrFail($source->id);
            abort_if($source->revoked_at, 409, 'Revoked sources cannot be rotated. Create a new source.');
            $source->update(['token_hash' => hash('sha256', $token), 'token_prefix' => substr($token, 0, 11)]);
            $this->audit($request, $source->branch_id, 'source.rotated', 'Rotated API key for '.$source->name);
        });

        return redirect('/integrations')->with('new_source_token', $token)->with('success', 'API key rotated. The previous key no longer works.');
    }

    public function revoke(Request $request, ApiSource $source)
    {
        BranchAccess::owner($request->user());
        DB::transaction(function () use ($request, $source) {
            $source = ApiSource::lockForUpdate()->findOrFail($source->id);
            if (! $source->revoked_at) {
                $source->update(['revoked_at' => now()]);
                $this->audit($request, $source->branch_id, 'source.revoked', 'Revoked API source '.$source->name);
            }
        });

        return redirect('/integrations')->with('success', 'Source revoked. It can no longer submit or read API events. Previously accepted events will still process.');
    }

    public function retry(Request $request, IngestionEvent $event)
    {
        BranchAccess::owner($request->user());
        DB::transaction(function () use ($request, $event) {
            $event = IngestionEvent::lockForUpdate()->findOrFail($event->id);
            abort_unless(in_array($event->status, ['failed', 'rejected']), 409, 'Only rejected or failed events can be retried.');
            $event->update(['status' => 'queued', 'error' => null, 'processed_at' => null]);
            ProcessIngestion::dispatch($event->id)->onConnection('ingestion')->beforeCommit();
            $this->audit($request, $event->branch_id, 'ingestion.retried', 'Retried API sale '.$event->reference);
        });

        return redirect('/integrations')->with('success', 'Event queued for another processing attempt.');
    }

    private function audit(Request $request, int $branch, string $action, string $description): void
    {
        AuditLog::create(['user_id' => $request->user()->id, 'branch_id' => $branch, 'action' => $action, 'description' => $description]);
    }
}
