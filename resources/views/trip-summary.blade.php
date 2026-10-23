@extends('layouts.app')

@section('title', 'Seyahat talebiniz')

@php
    $statusLabels = ['SEARCHABLE' => 'Aramaya hazır', 'DRAFT' => 'Taslak'];
    $searchable = $tripRequest->status === 'SEARCHABLE';
    $pace = $tripRequest->preferences['pace_preference'] ?? null;
@endphp

@section('content')
    @include('partials.flow-steps', ['current' => 'request'])

    <header class="page-header">
        <div>
            <p class="eyebrow">Seyahat talebi</p>
            <h1 class="page-title">{{ $origin['label'] }} <span aria-hidden="true">→</span><span class="visually-hidden">ile</span> {{ $destination['label'] }}</h1>
        </div>
        <span class="badge {{ $searchable ? 'badge-success' : 'badge-neutral' }}" data-status="{{ $tripRequest->status }}">
            {{ $statusLabels[$tripRequest->status] ?? $tripRequest->status }}
        </span>
    </header>

    @if (! $searchable)
        <div class="alert alert-warning" role="status">
            <p><strong>Bu talep henüz aramaya hazır değil.</strong> Eksik bilgiler bu görünümde sunulmuyor; yeni bir talep oluşturarak zorunlu alanları tamamlayabilirsiniz.</p>
        </div>
    @endif

    @if ($journey['legacy'])
        <div class="alert alert-warning" role="status" data-legacy-date>
            <p><strong>Bu talep eski tarih biçimiyle kaydedildi.</strong> Tarihin gidiş tarihi mi yoksa seyahat süresi mi olduğu kayıtlı olmadığından uçuş araması yapılamaz; tarih tahmin edilmez. Yolculuk tipini ve tarihleri seçerek yeni bir talep oluşturabilirsiniz.</p>
        </div>
    @endif

    <div class="layout-two">
        <section class="card" aria-labelledby="route-title">
            <h2 id="route-title" class="card-title">Rota ve tarihler</h2>
            <dl class="summary-list">
                @foreach (['Nereden' => $origin, 'Nereye' => $destination] as $heading => $place)
                    <div>
                        <dt>{{ $heading }}</dt>
                        <dd>
                            <span class="summary-strong">{{ $place['label'] }}</span>
                            <span class="summary-meta">{{ $place['kind'] }} · Yerel saat dilimi: {{ $place['timezone'] }}</span>
                            @unless ($place['resolved'])
                                <span class="summary-meta">{{ $place['catalog_identity'] ? 'Konum adı katalogdan şu anda alınamadı; kayıtlı konum kimliği teknik ayrıntılarda yer alır.' : 'Konum adı katalogdan şu anda alınamadı; kayıtlı konum değeri gösteriliyor.' }}</span>
                            @endunless
                        </dd>
                    </div>
                @endforeach
                @if ($journey['type'])
                    <div>
                        <dt>Yolculuk tipi</dt>
                        <dd><span class="summary-strong">{{ $journey['type'] }}</span></dd>
                    </div>
                @endif
                @foreach (['outbound' => 'Gidiş', 'return' => 'Dönüş'] as $direction => $directionLabel)
                    @if ($journey[$direction])
                        <div>
                            <dt>{{ $directionLabel }}</dt>
                            <dd>
                                <span class="summary-strong">{{ $journey[$direction]['text'] }}</span>
                                <span class="summary-meta">{{ $journey[$direction]['mode'] }}</span>
                            </dd>
                        </div>
                    @endif
                @endforeach
                @if ($journey['legacy'])
                    <div>
                        <dt>Ne zaman</dt>
                        <dd>
                            <span class="summary-strong">{{ $journey['legacy']['text'] }}</span>
                            <span class="summary-meta">{{ $journey['legacy']['mode'] }} · Eski tarih biçimi: gidiş/dönüş bilgisi yok</span>
                        </dd>
                    </div>
                @elseif (! $journey['outbound'])
                    <div>
                        <dt>Ne zaman</dt>
                        <dd><span class="summary-strong">Tarih bilgisi yok</span></dd>
                    </div>
                @endif
                <div>
                    <dt>Yolcu sayısı</dt>
                    <dd><span class="summary-strong">{{ $tripRequest->travelerCount }}</span></dd>
                </div>
                <div>
                    <dt>Tempo tercihi</dt>
                    <dd><span class="summary-strong">{{ $pace ?? 'Belirtilmedi' }}</span></dd>
                </div>
            </dl>
            <details class="technical">
                <summary>Teknik ayrıntılar</summary>
                <dl class="summary-list compact">
                    <div><dt>Talep numarası</dt><dd><code>{{ $tripRequestId }}</code></dd></div>
                    <div><dt>Durum kodu</dt><dd><code>{{ $tripRequest->status }}</code></dd></div>
                    <div><dt>Başlangıç konum kimliği</dt><dd><code>{{ $origin['value'] }}</code></dd></div>
                    <div><dt>Varış konum kimliği</dt><dd><code>{{ $destination['value'] }}</code></dd></div>
                </dl>
            </details>
        </section>

        <section class="card" aria-labelledby="next-title">
            <h2 id="next-title" class="card-title">Sonraki adımlar</h2>
            <ol class="next-steps">
                <li>
                    <h3>Yolcu bilgileri</h3>
                    <p>Yolcu bilgileri şu anda bu arayüzden eklenemiyor: yaş kategorisi için onaylı değer listesi ve yolcuların bağlanacağı seyahat (Trip) kaydı henüz tanımlı değil.</p>
                </li>
                <li>
                    <h3>Uçuş araması</h3>
                    @if (! $searchable)
                        <p>Talep aramaya hazır olduğunda uçuş araması başlatılabilir.</p>
                    @elseif ($flightSearchAvailable)
                        <p>Uçuş tekliflerini arayıp karşılaştırabilirsiniz.</p>
                        <a class="btn btn-primary" href="{{ route('flights.search', ['tripRequestId' => $tripRequestId]) }}">Uçuşları ara</a>
                    @else
                        <p>Uçuş sağlayıcısı henüz yapılandırılmadığı için arama şu anda başlatılamıyor.</p>
                        <a class="btn btn-secondary" href="{{ route('flights.search', ['tripRequestId' => $tripRequestId]) }}">Arama durumunu gör</a>
                    @endif
                </li>
                <li>
                    <h3>Seyahat Planım</h3>
                    <p>Seçtiğiniz uçuşu ve konaklamayı tek ekranda görün; konaklamayı buradan ekleyebilirsiniz.</p>
                    <a class="btn btn-secondary" href="{{ route('trip-plan.show', ['tripRequestId' => $tripRequestId]) }}">Seyahat Planım’ı aç</a>
                </li>
            </ol>
        </section>
    </div>
@endsection
