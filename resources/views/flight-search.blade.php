@extends('layouts.app')

@section('title', 'Uçuş araması')

@push('scripts')
    <script src="{{ asset('assets/flight.js') }}" defer></script>
@endpush

@section('content')
    @include('partials.flow-steps', ['current' => 'flights'])

    <header class="page-header">
        <div>
            <p class="eyebrow">Uçuş araması</p>
            <h1 class="page-title">Uçuş teklifleri</h1>
        </div>
        <a class="btn btn-secondary" href="{{ route('trip-planner.show', ['tripRequestId' => $tripRequestId]) }}">Talep özetine dön</a>
    </header>

    @include('partials.trip-brief')

    @if ($searchable && $dateBlocker)
        <div class="alert alert-warning" role="status" data-date-blocker>
            <p><strong>Bu talebin tarih bilgisiyle uçuş araması yapılamıyor.</strong> {{ $dateBlocker }} Tarihler değiştirilmez veya tahmin edilmez.</p>
        </div>
    @endif

    @if ($searchable && $locationBlocker)
        <div class="alert alert-warning" role="status" data-location-blocker>
            <p><strong>Bu talebin konumlarıyla uçuş araması yapılamıyor.</strong> {{ $locationBlocker }} Konumlar başka bir biçime dönüştürülmez veya tahmin edilmez.</p>
        </div>
    @endif

    @if ($searchable && $journeyBlocker)
        <div class="alert alert-warning" role="status" data-journey-blocker>
            <p><strong>Bu talebin yolculuk bilgisiyle uçuş araması yapılamıyor.</strong> {{ $journeyBlocker }} Talep iki ayrı tek yön aramaya bölünmez.</p>
        </div>
    @endif

    @if (! $searchable)
        <section class="card empty-state">
            <h2 class="card-title">Bu talep henüz uçuş aramasına hazır değil</h2>
            <p>Uçuş araması yalnız aramaya hazır seyahat talepleri için başlatılabilir. Zorunlu bilgileri tamamlanmış yeni bir talep oluşturun.</p>
            <a class="btn btn-primary" href="{{ route('trip-planner.index') }}">Yeni talep oluştur</a>
        </section>
    @elseif ($dateBlocker || $locationBlocker || $journeyBlocker)
        <section class="card empty-state" data-flight-search-blocked>
            <h2 class="card-title">Bu talep için uçuş araması başlatılamıyor</h2>
            @if ($journeyBlocker)
                <p>Yukarıda belirtilen nedenlerle arama sağlayıcıya gönderilmez. Şu anda kesin tarihli tek yön veya gidiş-dönüş uçuş araması yapılabilir; bunun için yeni bir talep oluşturabilirsiniz.</p>
            @else
                <p>Yukarıda belirtilen nedenlerle arama sağlayıcıya gönderilmez. Uçuş için havalimanı veya şehir aramasından seçilmiş konumlar ve yolculuk tipiyle birlikte gidiş (gerekiyorsa dönüş) tarihi içeren yeni bir talep oluşturabilirsiniz.</p>
            @endif
            <a class="btn btn-primary" href="{{ route('trip-planner.index') }}">Yeni talep oluştur</a>
        </section>
    @elseif (! $available)
        <section class="card empty-state" data-flight-search-unavailable>
            <h2 class="card-title">Uçuş araması şu anda kullanılamıyor</h2>
            <p>Onaylı bir uçuş sağlayıcısı henüz yapılandırılmadı. Sağlayıcı bağlandığında arama buradan başlatılabilecek.</p>
            <p class="summary-meta">Gerçek sağlayıcı verisi olmadan teklif, fiyat veya bagaj bilgisi gösterilmez.</p>
        </section>
    @else
        <section
            class="card"
            aria-labelledby="search-title"
            data-flight-search
            data-start-url="{{ route('web-api.flight-search-jobs.store') }}"
            data-poll-url="{{ route('web-api.flight-search-jobs.show', ['searchId' => '__SEARCH__']) }}"
            data-offer-url="{{ route('flights.offer', ['offerId' => '__OFFER__', 'talep' => $tripRequestId]) }}"
            data-storage-key="flight-search:{{ $tripRequestId }}"
            data-trip-request-id="{{ $tripRequestId }}"
            @if ($pollIntervalMs) data-poll-interval="{{ $pollIntervalMs }}" @endif
        >
            <h2 id="search-title" class="card-title">Arama durumu</h2>
            <p class="summary-meta">Arama, bu seyahat talebinin başlangıç ve varış konumları, yolculuk tipi, gidiş ve varsa dönüş tarihleri ile toplam yolcu sayısıyla yapılır. Tarihler ve konumlar değiştirilmez.</p>
            <div class="search-status" data-search-status role="status" aria-live="polite">
                <p>Arama henüz başlatılmadı.</p>
            </div>
            <div class="button-row">
                <button type="button" class="btn btn-primary" data-search-start>Uçuş aramasını başlat</button>
                <button type="button" class="btn btn-secondary" data-search-refresh hidden>Durumu yenile</button>
                <button type="button" class="btn btn-link" data-search-restart hidden>Yeni arama başlat</button>
                <p class="field-hint">Kısmi sonuçlarda mevcut teklifler korunur; sağlayıcı sorunu sürüyorsa daha sonra yeni bir arama başlatabilirsiniz.</p>
            </div>
        </section>

        <section class="results" aria-labelledby="results-title" data-search-results hidden>
            <div class="results-header">
                <h2 id="results-title" class="card-title">Teklifler</h2>
                <p class="summary-meta">Tam maliyeti bilinmeyen teklifler, tam maliyetli tekliflerin önüne alınmaz ve “en ucuz” sayılmaz. Teklif ayrıntısındaki maliyet kapsamı, bilinen alt sınırı ve bilinmeyen zorunlu kalemleri ayrı gösterir. Sıralama kişiselleştirilmiş bir öneri değildir.</p>
            </div>
            <ol class="offer-list" data-offer-list></ol>
        </section>
    @endif
@endsection
