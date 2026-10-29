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
    <div class="@yield('header-container', 'container') app-header-inner">
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
            <a class="nav-link" href="{{ route('trip-planner.index') }}" @if (request()->routeIs('trip-planner.index')) aria-current="page" @endif>Ana sayfa</a>
            <a class="nav-link" href="{{ route('trip-requests.mine') }}" @if (request()->routeIs('trip-requests.mine') || request()->routeIs('trip-planner.show') || request()->routeIs('flights.*') || request()->routeIs('trip-plan.*') || request()->routeIs('ground-connections.*')) aria-current="page" @endif>Seyahatlerim</a>
            <a class="nav-link" href="{{ route('trip-planner.index') }}#yeni-plan">Yeni plan</a>
            <a class="nav-link" href="{{ route('journey.index') }}" @if (request()->routeIs('journey.*') || request()->routeIs('capabilities.show')) aria-current="page" @endif>Yolculuk rehberi</a>
            {{-- ADR-010 browser login state; no identity detail is shown or exposed. --}}
            @if ($webSignedIn ?? false)
                {{-- Account menu (ui.js [data-dropdown]). The menu is hidden only once the script runs, so logout stays
                     reachable without JavaScript. --}}
                <div class="dropdown account-menu" data-dropdown>
                    <button type="button" class="nav-link nav-link-account" aria-haspopup="menu" aria-expanded="false" aria-controls="account-menu" data-dropdown-toggle>
                        Hesabım<x-ui.icon name="chevron-down" size="sm" />
                    </button>
                    <div class="menu menu-end" id="account-menu" role="menu" aria-label="Hesap" data-dropdown-menu>
                        <form class="nav-account" method="post" action="{{ route('auth.logout') }}">
                            @csrf
                            <button type="submit" class="menu-item" role="menuitem" data-menu-item>Çıkış yap</button>
                        </form>
                    </div>
                </div>
            @else
                <a class="nav-link nav-link-account" href="{{ route('auth.login', ['donus' => '/'.ltrim(request()->path(), '/')]) }}">Giriş yap</a>
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

<footer class="app-footer">
    <div class="container app-footer-inner">
        <p class="app-footer-brand">Seyahat Planlayıcısı</p>
        <p class="app-footer-note">Fiyat, uygunluk ve rezervasyon bilgileri yalnız sağlayıcıdan doğrulandığında kesin olarak gösterilir; tahmini veya doğrulanmamış bilgiler her zaman etiketlenir.</p>
        <nav class="app-footer-nav" aria-label="Alt gezinme">
            <a href="{{ route('trip-planner.index') }}">Ana sayfa</a>
            <a href="{{ route('trip-requests.mine') }}">Seyahatlerim</a>
            <a href="{{ route('journey.index') }}">Yolculuk rehberi</a>
            <a href="{{ route('capabilities.index') }}">Teknik özellik dizini</a>
        </nav>
    </div>
</footer>
</body>
</html>
