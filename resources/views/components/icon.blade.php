@props(['name'])
@php
$paths = match($name) {
 'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
 'sales' => '<path d="M4 19V5m0 14h16M8 14l4-4 4 2 5-7m-5 0h5v5"/>',
 'inventory' => '<path d="m12 3 9 5-9 5-9-5 9-5Zm-9 5v9l9 5 9-5V8M12 13v9M7 5.8l9 5"/>',
 'imports' => '<path d="M12 3v12m-4-4 4 4 4-4M4 15v5h16v-5"/>',
 'branches' => '<path d="M4 21V7l8-4 8 4v14H4Zm5 0v-5h6v5M8 8h1m6 0h1M8 12h1m6 0h1"/>',
 'products' => '<path d="M3 4h9l9 9-9 9-9-9V4Z"/><circle cx="8" cy="9" r="1.5"/>',
 'team' => '<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3m1-16a3 3 0 0 1 0 6m3 10v-3a6 6 0 0 0-2-4"/>',
 'integrations' => '<path d="M8 3v5m8-5v5M6 8h12v4a6 6 0 0 1-12 0V8Zm6 10v4"/>',
 'account' => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>',
 'transfers' => '<path d="M3 7h16m-4-4 4 4-4 4M21 17H5m4-4-4 4 4 4"/>',
 'reports' => '<path d="M5 3h10l4 4v14H5V3Zm9 0v5h5M8 17v-3m4 3v-6m4 6v-4"/>',
 'activity' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
 'logout' => '<path d="M9 4H4v16h5m5-12 4 4-4 4M8 12h12"/>',
 'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
 default => '<path d="M12 5v14M5 12h14"/>',
};
@endphp
<svg {{ $attributes->merge(['class'=>'icon']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $paths !!}</svg>
