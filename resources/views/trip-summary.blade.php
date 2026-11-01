{{-- Seyahat talebi ayrıntısı (TRIP-001). Yalnız kalıcı TripRequest verisi gösterilir.
     Varış fotoğrafı yalnız projeye eklenmiş, özgür lisanslı yerel bir dosyadan gelir (public/images/destinations/manifest.json:
     şehir → dosya, yazar, lisans, kaynak; havalimanı/şehir kodu → şehir). Eşleşme yoksa hero dekoratif bir degrade kullanır.
     Çalışma anında hiçbir dış görsel servisi çağrılmaz; fotoğraf "temsili" diye atfıyla gösterilir, yer kanıtı gibi sunulmaz. --}}
@extends('layouts.app')

@section('title', 'Seyahat talebiniz')
@section('body-class', 'app has-action-bar')
@section('main-class', 'trip-page')
@section('header-container', 'container container-wide')

@php
    $searchable = $tripRequest->status === 'SEARCHABLE';
    $pace = $tripRequest->preferences['pace_preference'] ?? null;
    $composition = $tripRequest->passengerComposition;
    $travelers = $composition ? implode(' · ', array_filter([
        $composition['adults'] > 0 ? $composition['adults'].' yetişkin' : null,
        $composition['children'] > 0 ? $composition['children'].' çocuk' : null,
        $composition['infants'] > 0 ? $composition['infants'].' bebek' : null,
    ])) : $tripRequest->travelerCount.' yolcu';
    $places = ['Nereden' => $origin, 'Nereye' => $destination];
    $showCodes = $origin['code'] && $destination['code'] && ($origin['code'] !== $origin['label'] || $destination['code'] !== $destination['label']);

    $photos = is_file(public_path('images/destinations/manifest.json'))
        ? json_decode((string) file_get_contents(public_path('images/destinations/manifest.json')), true) : null;
    $photo = null;
    if (is_array($photos)) {
        // A human-readable label (resolved catalog name or plain stored name) can match a city; catalog ids never do.
        $nameKey = ($destination['resolved'] || ! $destination['catalog_identity']) ? \Illuminate\Support\Str::slug($destination['label']) : null;
        $slug = $photos['codes'][$destination['code'] ?? ''] ?? ($nameKey !== null ? ($photos['aliases'][$nameKey] ?? $nameKey) : null);
        $entry = $slug !== null ? ($photos['photos'][$slug] ?? null) : null;
        if ($entry && is_file(public_path('images/destinations/'.$entry['file']))) {
            $photo = $entry + ['url' => asset('images/destinations/'.$entry['file'])];
        }
    }
    $flightUrl = route('flights.search', ['tripRequestId' => $tripRequestId]);
    $planUrl = route('trip-plan.show', ['tripRequestId' => $tripRequestId]);
@endphp

@section('content')
    <section @class(['trip-hero', 'has-photo' => $photo]) aria-label="Rota ve durum">
        @if ($photo)
            <img class="trip-hero-photo" src="{{ $photo['url'] }}" alt="" width="1920" height="{{ $photo['height'] ?? 1080 }}" fetchpriority="high">
            <p class="trip-hero-credit">Temsili fotoğraf: <a href="{{ $photo['source'] }}" rel="noopener noreferrer" target="_blank">{{ $photo['artist'] }}</a> · {{ $photo['license'] }}<span class="visually-hidden"> (yeni sekmede açılır)</span></p>
        @endif
        <div class="container container-wide trip-hero-inner">
            <nav class="trip-breadcrumb" aria-label="Sayfa yolu">
                <a href="{{ route('trip-requests.mine') }}"><x-ui.icon name="chevron-down" size="sm" class="trip-breadcrumb-back" />Seyahatlerim</a>
                <span aria-hidden="true">›</span>
                <span aria-current="page">Seyahat talebi</span>
            </nav>

            <h1 class="page-title">{{ $origin['label'] }} <span aria-hidden="true">→</span><span class="visually-hidden">ile</span> {{ $destination['label'] }}</h1>

            <div class="trip-hero-meta">
                <p class="trip-hero-places">
                    @if ($showCodes)
                        <span>{{ $origin['code'] }}</span> <span aria-hidden="true">→</span><span class="visually-hidden">ile</span> <span>{{ $destination['code'] }}</span>
                    @else
                        <span>{{ $origin['kind'] }}</span> <span aria-hidden="true">→</span><span class="visually-hidden">ile</span> <span>{{ $destination['kind'] }}</span>
                    @endif
                </p>
                <span class="badge {{ $searchable ? 'badge-success' : 'badge-neutral' }}" data-status="{{ $tripRequest->status }}">
                    {{ $statusLabel }}
                </span>
            </div>

            <div class="trip-hero-bar">
                <dl class="trip-facts">
                    <div>
                        <x-ui.icon name="calendar" />
                        <dt class="visually-hidden">Tarihler</dt>
                        <dd>
                            @if ($journey['range'])
                                <strong>{{ $journey['range'] }}</strong>
                            @elseif ($journey['outbound'])
                                <strong>{{ $journey['outbound']['text'] }}</strong>
                            @elseif ($journey['legacy'])
                                <strong>{{ $journey['legacy']['text'] }}</strong>
                            @else
                                <strong>Tarih bilgisi yok</strong>
                            @endif
                            <span>{{ $journey['nights'] !== null ? $journey['nights'].' gece' : 'Tarihler' }}</span>
                        </dd>
                    </div>
                    @if ($journey['type'])
                        <div><x-ui.icon name="route" /><dt class="visually-hidden">Yolculuk tipi</dt><dd><strong>{{ $journey['type'] }}</strong><span aria-hidden="true">Yolculuk tipi</span></dd></div>
                    @endif
                    <div><x-ui.icon name="users" /><dt class="visually-hidden">Yolcu sayısı</dt><dd><strong>{{ $travelers }}</strong><span aria-hidden="true">Yolcu sayısı</span></dd></div>
                    @if ($tripRequest->roomCount !== null)
                        <div><x-ui.icon name="bed" /><dt class="visually-hidden">Konaklama</dt><dd><strong>{{ $tripRequest->roomCount }} oda</strong><span aria-hidden="true">Konaklama</span></dd></div>
                    @endif
                </dl>

                {{-- Inline in the hero on wide screens; a sticky bottom bar on phones. --}}
                <div class="trip-actions" role="group" aria-label="Bu seyahat için ana eylem">
                    @if (! $searchable)
                        <a class="btn btn-accent btn-lg" href="{{ route('trip-planner.index') }}">Yeni talep oluştur</a>
                    @elseif ($flightSearchAvailable)
                        <a class="btn btn-accent btn-lg" href="{{ $flightUrl }}"><x-ui.icon name="search" />Uçuşları ara<x-ui.icon name="arrow-right" size="sm" /></a>
                    @else
                        <a class="btn btn-secondary btn-lg" href="{{ $flightUrl }}">Arama durumunu gör</a>
                    @endif
                </div>
            </div>
        </div>
    </section>

    <div class="container container-wide trip-body">
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

        <div class="trip-detail-grid">
            <div class="trip-main">
                <section class="trip-card" aria-labelledby="trip-details-title">
                    <h2 id="trip-details-title" class="trip-card-title"><span class="trip-card-icon"><x-ui.icon name="list-check" /></span>Seyahat detayları</h2>
                    <dl class="trip-rows">
                        @foreach (['outbound' => ['Gidiş', 'plane'], 'return' => ['Dönüş', 'plane']] as $direction => [$directionLabel, $icon])
                            @if ($journey[$direction])
                                <div>
                                    <dt><x-ui.icon :name="$icon" size="sm" :class="$direction === 'return' ? 'trip-row-return' : ''" />{{ $directionLabel }}</dt>
                                    <dd>{{ $journey[$direction]['text'] }}</dd>
                                    <dd class="trip-row-meta">{{ $journey[$direction]['mode'] }}</dd>
                                </div>
                            @endif
                        @endforeach
                        @if ($journey['legacy'])
                            <div>
                                <dt><x-ui.icon name="calendar" size="sm" />Ne zaman</dt>
                                <dd>{{ $journey['legacy']['text'] }}</dd>
                                <dd class="trip-row-meta">{{ $journey['legacy']['mode'] }} · Eski tarih biçimi: gidiş/dönüş bilgisi yok</dd>
                            </div>
                        @elseif (! $journey['outbound'])
                            <div><dt><x-ui.icon name="calendar" size="sm" />Ne zaman</dt><dd>Tarih bilgisi yok</dd></div>
                        @endif
                        @if ($journey['type'])
                            <div><dt><x-ui.icon name="route" size="sm" />Yolculuk tipi</dt><dd>{{ $journey['type'] }}</dd></div>
                        @endif
                        @if ($composition)
                            @foreach (['adults' => 'Yetişkin sayısı', 'children' => 'Çocuk sayısı', 'infants' => 'Bebek sayısı'] as $type => $typeLabel)
                                @if ($type === 'adults' || $composition[$type] > 0)
                                    <div><dt><x-ui.icon name="users" size="sm" />{{ $typeLabel }}</dt><dd>{{ $composition[$type] }} {{ ['adults' => 'yetişkin', 'children' => 'çocuk', 'infants' => 'bebek'][$type] }}</dd></div>
                                @endif
                            @endforeach
                        @else
                            <div><dt><x-ui.icon name="users" size="sm" />Yolcu sayısı</dt><dd>{{ $tripRequest->travelerCount }} yolcu</dd></div>
                        @endif
                        @if ($tripRequest->roomCount !== null)
                            <div><dt><x-ui.icon name="bed" size="sm" />Konaklama</dt><dd>{{ $tripRequest->roomCount }} oda</dd></div>
                        @endif
                        @foreach ($places as $heading => $place)
                            <div>
                                <dt><x-ui.icon name="map-pin" size="sm" />{{ $heading }}</dt>
                                <dd>
                                    {{ $place['label'] }}
                                    <span class="trip-row-sub">{{ $place['kind'] }} · Yerel saat dilimi: {{ $place['timezone'] }}</span>
                                    @unless ($place['resolved'])
                                        <span class="trip-row-sub">Konum adı katalogdan şu anda alınamadı; doğrulanmamış bir ad gösterilmiyor.</span>
                                    @endunless
                                </dd>
                            </div>
                        @endforeach
                        <div><dt><x-ui.icon name="compass" size="sm" />Tempo tercihi</dt><dd>{{ $pace ?? 'Belirtilmedi' }}</dd></div>
                    </dl>

                    <div class="trip-disclosures">
                        <details class="trip-disclosure">
                            <summary><x-ui.icon name="users" /><span><strong>Yolcu bilgileri</strong> <span aria-hidden="true">·</span> <span class="trip-disclosure-note">Henüz eklenemiyor</span></span><x-ui.icon name="chevron-down" size="sm" class="trip-disclosure-chevron" /></summary>
                            <p>Yaş kategorisi için onaylı değer listesi ve yolcuların bağlanacağı seyahat kaydı henüz tanımlı olmadığından yolcu bilgileri bu arayüzden eklenemiyor.</p>
                        </details>
                    </div>
                </section>
            </div>

            <aside class="trip-card trip-next" aria-labelledby="next-title">
                <h2 id="next-title" class="trip-card-title">Sonraki adımlar</h2>
                <div class="flow-steps-vertical">
                    @include('partials.flow-steps', ['current' => 'request', 'descriptions' => [
                        'request' => 'Rota, tarih ve yolcu sayısı kayıtlı.',
                        'flights' => $searchable
                            ? ($flightSearchAvailable ? 'Kriterlerinize uygun uçuşları arayıp karşılaştırın.' : 'Uçuş sağlayıcısı henüz yapılandırılmadığı için arama şu anda başlatılamıyor.')
                            : 'Talep aramaya hazır olduğunda başlatılabilir.',
                        'offer' => 'Fiyat ve koşulları yeniden kontrol edip seçtiğiniz uçuşu planınıza ekleyin.',
                    ]])
                </div>
                <div class="trip-next-actions">
                    @if ($searchable && $flightSearchAvailable)
                        <a class="btn btn-accent btn-lg btn-block" href="{{ $flightUrl }}"><x-ui.icon name="search" />Uçuşları ara<x-ui.icon name="arrow-right" size="sm" /></a>
                    @endif
                    <a class="btn btn-secondary btn-lg btn-block" href="{{ $planUrl }}"><x-ui.icon name="calendar" />Seyahat Planım’a git</a>
                </div>
                <nav class="trip-next-links" aria-label="Bu seyahat için diğer seçenekler">
                    <a href="{{ route('journey.category', ['categoryId' => 'ulasim']) }}">Ulaşım seçenekleri</a>
                    <a href="{{ route('journey.category', ['categoryId' => 'konaklama-kesif']) }}">Konaklama ve keşif</a>
                    <a href="{{ route('journey.category', ['categoryId' => 'hazirlik-yolculuk']) }}">Hazırlık ve yolculuk</a>
                </nav>
            </aside>
        </div>
    </div>
@endsection
