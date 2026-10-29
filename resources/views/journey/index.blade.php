@extends('layouts.app')

@section('title', 'Yolculuk rehberi')
@section('description', 'Seyahatinizi planlama, ulaşım, keşif, günlük plan ve hazırlık adımlarına göre yönetin.')
@section('main-class', 'container-wide page')

@section('content')
    @php
        $userFacingCount = collect($stories)->where('user_facing', true)->count();
        $availableCount = collect($stories)->where('user_facing', true)->filter(fn (array $story) => $story['route'] !== null)->count();
    @endphp

    <header class="journey-hero">
        <div>
            <p class="eyebrow">Yolculuk rehberi</p>
            <h1>Seyahatinizi adım adım yönetin</h1>
            <p>Teknik özellik listeleri arasında kaybolmadan, yapmak istediğiniz işe göre doğru ekrana ulaşın.</p>
        </div>
        <div class="journey-hero-actions">
            <a class="btn btn-primary" href="{{ route('trip-planner.index') }}#yeni-plan">Yeni plan oluştur</a>
            <a class="btn btn-secondary" href="{{ route('trip-requests.mine') }}">Seyahatlerim</a>
        </div>
    </header>

    <div class="status-legend" role="note" aria-label="Özellik durumları">
        <span class="badge badge-success">Kullanılabilir</span><span>Çalışan uygulama akışına bağlanır.</span>
        <span class="badge badge-unavailable">Planlanıyor</span><span>Ne sunacağı görülebilir; işlem yapılamaz.</span>
    </div>

    <ul class="journey-category-grid journey-category-grid-wide">
        @foreach ($categories as $categoryId => $category)
            @php
                $categoryItems = collect($categoryStories[$categoryId])->map(fn (string $id) => $stories[$id]);
                $working = $categoryItems->filter(fn (array $story) => $story['route'] !== null)->count();
            @endphp
            <li>
                <a class="journey-category-card" href="{{ route('journey.category', ['categoryId' => $categoryId]) }}">
                    <span class="icon-tile"><x-ui.icon :name="$category['icon']" /></span>
                    <strong>{{ $category['title'] }}</strong>
                    <span>{{ $category['description'] }}</span>
                    <small>{{ $categoryItems->count() }} konu · {{ $working }} kullanılabilir akış</small>
                </a>
            </li>
        @endforeach
    </ul>

    <section class="reference-callout" aria-labelledby="reference-title">
        <div>
            <h2 id="reference-title">Teknik özellik dizini</h2>
            <p>{{ $userFacingCount }} yolcu ekranının yanında altyapı, güvenilirlik ve denetim yeteneklerini de EPIC ve gereksinim kimlikleriyle inceleyin.</p>
        </div>
        <a class="btn btn-secondary" href="{{ route('capabilities.index') }}">Teknik dizini aç</a>
    </section>
@endsection
