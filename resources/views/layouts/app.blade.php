<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@hasSection('title')@yield('title') · @endif{{ 'Seyahat Planlayıcısı' }}</title>
    <meta name="description" content="@yield('description', 'Seyahat talebinizi oluşturun, yolcu bilgilerini ekleyin ve uçuş seçeneklerini karşılaştırın.')">
    {{-- Versioned by mtime so a deploy never pairs new markup with a stale cached stylesheet. --}}
    <link rel="stylesheet" href="{{ asset('assets/design-tokens.css') }}?v={{ @filemtime(public_path('assets/design-tokens.css')) }}">
    <link rel="stylesheet" href="{{ asset('assets/trip-planner.css') }}?v={{ @filemtime(public_path('assets/trip-planner.css')) }}">
    <script src="{{ asset('assets/ui.js') }}?v={{ @filemtime(public_path('assets/ui.js')) }}" defer></script>
    @stack('scripts')
</head>
<body class="@yield('body-class', 'app')">
<a class="skip-link" href="#main">İçeriğe geç</a>

<header class="app-header" data-app-header>
    <div class="container app-header-inner">
        <a class="brand" href="{{ route('trip-planner.index') }}">
            {{-- Marka işareti rota çizgisidir: kalkış noktası, yol ve mercan varış noktası. --}}
            <svg class="brand-mark" viewBox="0 0 36 24" aria-hidden="true" focusable="false">
                <path d="M5 19C9 5 27 5 31 19" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-dasharray="1 5"/>
                <circle cx="5" cy="19" r="3" fill="currentColor"/>
                <circle class="brand-mark-end" cx="31" cy="19" r="3"/>
            </svg>
            <span class="brand-name">Seyahat Planlayıcısı</span>
        </a>
        {{-- Shown by ui.js only; without the script the links below stay visible. --}}
        <button type="button" class="icon-btn nav-toggle" aria-label="Menü" aria-controls="primary-nav" aria-expanded="false" data-nav-toggle hidden>
            <x-ui.icon name="menu" />
        </button>
        <nav class="app-nav" id="primary-nav" aria-label="Ana gezinme">
            <a class="nav-link" href="{{ route('trip-planner.index') }}" @if (request()->routeIs('trip-planner.index')) aria-current="page" @endif>Yeni plan</a>
            <a class="nav-link" href="{{ route('trip-requests.mine') }}" @if (request()->routeIs('trip-requests.mine')) aria-current="page" @endif>Taleplerim</a>
            {{-- ADR-010 browser login state; no identity detail is shown or exposed. --}}
            @if ($webSignedIn ?? false)
                <form method="post" action="{{ route('auth.logout') }}">
                    @csrf
                    <button type="submit" class="nav-link">Çıkış yap</button>
                </form>
            @else
                <a class="nav-link" href="{{ route('auth.login', ['donus' => '/'.ltrim(request()->path(), '/')]) }}">Giriş yap</a>
            @endif
        </nav>
    </div>
</header>

<main id="main" class="@yield('main-class', 'container page')" tabindex="-1">
    @if (session('auth_error'))
        <p class="alert alert-danger" role="alert">{{ session('auth_error') }}</p>
    @endif
    @yield('content')
</main>
</body>
</html>
