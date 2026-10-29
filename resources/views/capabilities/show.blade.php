@extends('layouts.app')

@php
    $screens = config('frontend_screens');
    $story = $screens['stories'][request()->segment(2)] ?? null;
    abort_unless($story, 404);
    $category = $story['category'] ? $screens['categories'][$story['category']] : null;
    $classificationLabels = [
        'main_navigation' => 'Doğrudan erişim',
        'category_subpage' => 'Yolculuk rehberi',
        'trip_context' => 'Seyahate özel',
        'contextual' => 'Plan içinde açılır',
        'internal' => 'Teknik / iç yetenek',
    ];
@endphp

@section('title', $story['title'])
@section('description', $story['value'])
@section('main-class', 'container-wide page')

@section('content')
    <nav class="breadcrumb" aria-label="Sayfa yolu">
        @if ($category)
            <a href="{{ route('journey.index') }}">Yolculuk rehberi</a>
            <span aria-hidden="true">/</span>
            <a href="{{ route('journey.category', ['categoryId' => $story['category']]) }}">{{ $category['title'] }}</a>
        @else
            <a href="{{ route('capabilities.index') }}">Teknik özellik dizini</a>
        @endif
        <span aria-hidden="true">/</span>
        <span aria-current="page">{{ $story['title'] }}</span>
    </nav>

    <header class="feature-header">
        <div>
            <p class="eyebrow">{{ $category['title'] ?? 'Teknik özellik' }} · {{ $classificationLabels[$story['classification']] }}</p>
            <h1>{{ $story['title'] }}</h1>
            <p>{{ $story['value'] }}</p>
        </div>
        <span class="badge {{ $story['route'] ? 'badge-success' : 'badge-unavailable' }}">{{ $story['route'] ? 'Kullanılabilir' : 'Planlanıyor' }}</span>
    </header>

    @if ($story['route'])
        <section class="availability availability-ready" aria-labelledby="availability-title">
            <x-ui.icon name="success" size="lg" />
            <div>
                <h2 class="availability-title" id="availability-title">Bu özellik kullanılabilir</h2>
                <p>Çalışan uygulama akışına geçerek kendi seyahat bilgilerinizle devam edebilirsiniz.</p>
            </div>
            <a class="btn btn-primary btn-lg" href="{{ route($story['route']) }}">Akışa git</a>
        </section>
    @else
        <section class="availability" aria-labelledby="availability-title">
            <x-ui.icon name="warning" size="lg" />
            <div>
                <h2 class="availability-title" id="availability-title">Bu özellik henüz işlem yapmıyor</h2>
                <p>Ne sunacağı aşağıda görülebilir; ancak şu anda veri kaydetmez, hesaplama yapmaz, doğrulama veya rezervasyon başlatmaz.</p>
            </div>
            <a class="btn btn-secondary" href="{{ $category ? route('journey.category', ['categoryId' => $story['category']]) : route('capabilities.index') }}">Güvenli dönüş</a>
        </section>
    @endif

    <div class="feature-experience-grid">
        <section class="panel" aria-labelledby="find-title">
            <h2 class="panel-title" id="find-title">Bu ekranda ne bulacaksınız?</h2>
            <p>{{ $story['scope'] }}</p>
            @if ($story['tasks'] !== [])
                <ul class="feature-task-list">
                    @foreach ($story['tasks'] as $task)
                        <li>{{ $task }}</li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="panel panel-trust" aria-labelledby="trust-title">
            <h2 class="panel-title" id="trust-title"><x-ui.icon name="shield" /> Bilginin güven sınırı</h2>
            <p>{{ $story['out'] }}</p>
            <p class="empty-note">Doğrulanmamış bilgi başarılı sonuç, kesin fiyat, uygunluk veya rezervasyon olarak gösterilmez.</p>
        </section>
    </div>

    @if ($story['data'])
        <section class="panel feature-data" aria-labelledby="data-title">
            <h2 class="panel-title" id="data-title">Kullanılacak bilgiler</h2>
            <p>{{ $story['data'] }}</p>
            @unless ($story['route'])
                <p class="field-hint">Bu alanlar yalnız kapsamı açıklar; ekranda örnek ya da uydurma veri üretilmez.</p>
            @endunless
        </section>
    @endif

    <details class="panel technical feature-reference">
        <summary>Analiz, kurallar ve kabul kontrolleri</summary>
        @if ($story['rules'] !== [])
            <h2 class="capability-subtitle">Güven kuralları</h2>
            <ul class="rule-list">
                @foreach ($story['rules'] as $rule)
                    <li>{{ $rule }}</li>
                @endforeach
            </ul>
        @endif
        @if ($story['checks'] !== [])
            <h2 class="capability-subtitle">Beklenen davranışlar</h2>
            <ol class="check-list">
                @foreach ($story['checks'] as $check)
                    <li class="check-card">
                        <dl>
                            @if ($check['given'])
                                <div><dt>Durum</dt><dd>{{ $check['given'] }}</dd></div>
                                <div><dt>Eylem</dt><dd>{{ $check['when'] }}</dd></div>
                            @endif
                            <div class="check-result"><dt>Beklenen</dt><dd>{{ $check['then'] }}</dd></div>
                        </dl>
                    </li>
                @endforeach
            </ol>
        @endif
        <h2 class="capability-subtitle">İzlenebilirlik</h2>
        <ul class="requirement-list" aria-label="Gereksinim kimlikleri">
            @foreach ($story['requirements'] as $requirement)
                <li><code>{{ $requirement }}</code></li>
            @endforeach
        </ul>
    </details>
@endsection
