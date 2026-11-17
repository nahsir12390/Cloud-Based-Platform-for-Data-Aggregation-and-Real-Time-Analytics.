<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ ucfirst($page ?? 'Login') }} · BranchFlow</title>
    <link rel="stylesheet" href="/css/app.css?v=3">
    @livewireStyles
</head>
<body class="{{ auth()->check() ? 'workspace-body' : 'guest-body' }}">
@auth
<div class="app-frame" x-data="{ navigationOpen: false }">
    <div class="mobile-topbar"><a class="brand" href="/dashboard"><span class="brand-mark">B<span>↗</span></span>BranchFlow<span class="brand-dot">.</span></a><button class="menu-toggle" @click="navigationOpen = !navigationOpen" :aria-expanded="navigationOpen" aria-expanded="false" aria-controls="workspace-sidebar" aria-label="Toggle navigation"><x-icon name="menu" /></button></div>
    <aside class="sidebar" id="workspace-sidebar" :class="{ 'is-open': navigationOpen }">
        <a class="brand" href="/dashboard" aria-label="BranchFlow overview"><span class="brand-mark">B<span>↗</span></span>BranchFlow<span class="brand-dot">.</span></a>
        <div class="business"><span class="business-icon"><x-icon name="branches" /></span><div><strong>Retail workspace</strong><small>Sales & inventory</small></div><span class="workspace-badge">{{ ucfirst(auth()->user()->role) }}</span></div>
        <nav class="main-nav" aria-label="Main navigation">
            <span class="nav-label">WORKSPACE</span>
            @foreach(['dashboard'=>'Overview','sales'=>'Sales transactions','inventory'=>'Inventory','transfers'=>'Stock transfers','imports'=>'Data imports','reports'=>'Reports'] as $key=>$label)
                <a href="/{{ $key }}" @if(($page??'')===$key) class="active" aria-current="page" @endif><x-icon :name="$key" /><span>{{ $label }}</span></a>
            @endforeach
            <span class="nav-label management-label">MANAGEMENT</span>
            @if(auth()->user()->role==='owner')
                @foreach(['branches'=>'Branches','products'=>'Product catalog','team'=>'Team members','integrations'=>'Integrations'] as $key=>$label)
                    <a href="/{{ $key }}" @if(($page??'')===$key) class="active" aria-current="page" @endif><x-icon :name="$key" /><span>{{ $label }}</span></a>
                @endforeach
            @endif
            <a href="/activity" @if(($page??'')==='activity') class="active" aria-current="page" @endif><x-icon name="activity" /><span>Audit activity</span></a>
            <a href="/account" @if(($page??'')==='account') class="active" aria-current="page" @endif><x-icon name="account" /><span>My account</span></a>
        </nav>
        <div class="sidebar-bottom"><div class="workspace-note"><span class="note-dot"></span>Branch data in one workspace</div><div class="profile"><span class="avatar">{{ substr(auth()->user()->name,0,1) }}</span><div><strong>{{ auth()->user()->name }}</strong><small>{{ ucfirst(auth()->user()->role) }} account</small></div><form method="POST" action="/logout">@csrf<button class="logout" title="Sign out" aria-label="Sign out"><x-icon name="logout" /></button></form></div></div>
    </aside>
    <div class="shell">
        <header class="workspace-header"><div class="breadcrumb">Workspace <span>/</span> <strong>{{ $page==='dashboard'?'Overview':ucfirst($page??'Overview') }}</strong></div><div class="header-meta"><span class="header-date">{{ now()->format('D, d M Y') }}</span><span class="timezone-tag">WAT</span></div></header>
        <main>
            @if(session('success'))<div class="notice success" role="status">{{ session('success') }}</div>@endif
            @if($errors->any())<div class="notice error" role="alert"><strong>Please check your entry.</strong>@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
            @yield('content')
        </main>
        <footer><span>BranchFlow <span class="footer-dot">/</span> Sales & inventory analytics</span><span>Currency: NGN <span class="footer-dot">·</span> Africa/Lagos</span></footer>
    </div>
</div>
@else
    @yield('content')
@endauth
@livewireScripts
</body>
</html>
