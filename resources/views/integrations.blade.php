@extends('layouts.app')
@section('content')
<div class="page-heading"><div><span class="eyebrow">DATA AGGREGATION</span><h1>Integrations</h1><p class="muted">Connect branch systems and track incoming sales through to processing.</p></div><a class="button secondary" href="#api-contract">View API format ↓</a></div>
@if(session('new_source_token'))
<section class="panel token-panel" role="status"><h2>Save your API key</h2><p>This key is shown once. Store it securely in the sending system. It only grants access to its assigned branch.</p><label>New API key<input id="new-source-token" value="{{ session('new_source_token') }}" readonly autocomplete="off" spellcheck="false" onclick="this.select()"></label><small>Rotating a key invalidates the previous key immediately.</small></section>
@endif
<section class="panel entry-panel"><h2>Add a data source</h2><form method="POST" action="/integrations/sources" class="entry-form">@csrf<label>Source name<input name="name" value="{{ old('name') }}" maxlength="100" placeholder="e.g. Wuse checkout terminal" required></label><label>Assigned branch<select name="branch_id" aria-label="Assigned branch" required><option value="">Select a branch</option>@foreach($branches as $b)<option value="{{ $b->id }}" @selected(old('branch_id')==$b->id)>{{ $b->name }}</option>@endforeach</select></label><button class="button">Create source & API key</button><p class="form-help">Each source has its own branch-scoped key. Incoming sales use your catalog prices and available branch stock.</p></form></section>
<livewire:ingestion-monitor />
<section class="panel api-contract" id="api-contract"><div class="panel-heading"><div><h2>Send a sale from a branch system</h2><p>Submit a JSON transaction, then check its processing status.</p></div></div><div class="contract-body"><div class="endpoint"><span class="badge green">POST</span><code>{{ url('/api/v1/sales') }}</code></div><p>Headers: <code>Authorization: Bearer YOUR_API_KEY</code>, <code>Content-Type: application/json</code>, <code>Accept: application/json</code></p><pre><code>{
  "reference": "550e8400-e29b-41d4-a716-446655440000",
  "sku": "YOUR-PRODUCT-SKU",
  "quantity": 2,
  "sold_at": "{{ now()->subMinute()->format('Y-m-d\TH:i:sP') }}"
}</code></pre><p>A <strong>202</strong> response means accepted for processing. Stock changes only when the status becomes <strong>completed</strong>. Use <code>GET /api/v1/sales/{reference}</code> with the same key to check status.</p><p>Keep the reference and payload unchanged when retrying a request. Branch and prices come from the source and catalog. Limit: 120 requests per minute per source; 16 KB per request.</p></div></section>
@endsection
