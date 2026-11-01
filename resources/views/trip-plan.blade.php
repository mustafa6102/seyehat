{{-- Seyahat Planım (TRIP-BLD-001). $plan, TripPlannerController::plan tarafından yalnız kalıcı TripRequest verisi ve
     v3.13 uçuş seçimiyle doldurulur; örnek veri yoktur. Bilinmeyen tutarlar null kalır ve "Henüz hesaplanmadı" yazılır; hiçbir zaman 0 gösterilmez. --}}
@extends('layouts.app')

@section('title', 'Seyahat Planım')

@push('scripts')
    <script src="{{ asset('assets/flight-opportunity.js') }}?v={{ @filemtime(public_path('assets/flight-opportunity.js')) }}" defer></script>
    <script src="{{ asset('assets/accommodation.js') }}?v={{ @filemtime(public_path('assets/accommodation.js')) }}" defer></script>
    <script src="{{ asset('assets/poi.js') }}?v={{ @filemtime(public_path('assets/poi.js')) }}" defer></script>
    <script src="{{ asset('assets/event.js') }}?v={{ @filemtime(public_path('assets/event.js')) }}" defer></script>
    <script src="{{ asset('assets/plan-days.js') }}?v={{ @filemtime(public_path('assets/plan-days.js')) }}" defer></script>
    <script src="{{ asset('assets/how-guide.js') }}?v={{ @filemtime(public_path('assets/how-guide.js')) }}" defer></script>
@endpush

@php
    $types = [
        'flight' => ['icon' => 'plane', 'label' => 'Uçuş'],
        'stay' => ['icon' => 'bed', 'label' => 'Konaklama'],
        'sight' => ['icon' => 'map-pin', 'label' => 'Gezilecek yer'],
        'food' => ['icon' => 'utensils', 'label' => 'Restoran'],
        'event' => ['icon' => 'ticket', 'label' => 'Etkinlik'],
        'free' => ['icon' => 'clock', 'label' => 'Serbest zaman'],
    ];
    $actions = [
        'stay' => ['label' => 'Konaklama ekle', 'empty' => 'Konaklama seçenekleri yakında burada listelenecek.'],
        'sight' => ['label' => 'Gezilecek yer ekle', 'empty' => 'Gezilecek yer önerileri yakında burada listelenecek.'],
        'food' => ['label' => 'Restoran ekle', 'empty' => 'Restoran önerileri yakında burada listelenecek.'],
        'event' => ['label' => 'Etkinlik ekle', 'empty' => 'Etkinlik önerileri yakında burada listelenecek.'],
    ];
    $costs = ['flight' => 'Uçuş', 'stay' => 'Konaklama', 'activities' => 'Etkinlik ve gezi', 'other' => 'Diğer giderler'];
    $unknown = 'Henüz hesaplanmadı';
    $allCostsKnown = ! in_array(null, $plan['costs'], true);
@endphp

@section('content')
    <header class="page-header">
        <div>
            <h1 class="page-title">Seyahat Planım</h1>
            <p class="plan-context">
                <span class="fact"><x-ui.icon name="map-pin" size="sm" />{{ $plan['destination'] }}</span>
                <span class="fact"><x-ui.icon name="calendar" size="sm" />{{ $plan['dates'] }}</span>
                <x-ui.badge>{{ $plan['status'] }}</x-ui.badge>
            </p>
        </div>
    </header>

    @if ($planAccess === 'OWNER' && $poiPlan && $poiPlan['version'] !== null)
        <p><a class="btn btn-secondary" href="{{ route('trip-plan.history', ['tripRequestId' => $tripRequestId]) }}">Plan değişiklik geçmişini gör</a></p>
    @endif

    <h2 class="visually-hidden">Plan özeti</h2>
    <ul class="overview-grid">
        <li class="overview-card">
            <x-ui.icon name="map-pin" />
            <span class="overview-label">Varış noktası</span>
            <span class="overview-value">{{ $plan['destination'] }}</span>
        </li>
        <li class="overview-card">
            <x-ui.icon name="calendar" />
            <span class="overview-label">Tarihler</span>
            <span class="overview-value">{{ $plan['dates'] }}</span>
        </li>
        <li class="overview-card">
            <x-ui.icon name="users" />
            <span class="overview-label">Yolcular</span>
            <span class="overview-value">{{ $plan['travelers'] }}</span>
        </li>
        <li @class(['overview-card', 'is-missing' => ! $plan['flight']])>
            <x-ui.icon name="plane" />
            <span class="overview-label">Uçuş</span>
            @if ($plan['flight'])
                <span class="overview-value">{{ $plan['flight']['route'] }}</span>
                <span class="overview-label">{{ $plan['flight']['detail'] }}</span>
                <x-ui.badge :variant="['current' => 'success', 'reconfirm' => 'warning', 'expired' => 'error'][$plan['flight']['state']]">{{ $plan['flight']['state_label'] }}</x-ui.badge>
                <span class="overview-label">{{ $plan['flight']['verified'] }}</span>
                <span class="overview-label">{{ $plan['flight']['expires'] }}</span>
                <x-ui.button variant="secondary" size="sm" :href="$plan['flight']['manage_url']">{{ $plan['flight']['state'] === 'reconfirm' ? 'Uçuşu yeniden onayla' : ($plan['flight']['state'] === 'expired' ? 'Yeni uçuş ara' : 'Uçuş seçimini gözden geçir') }}</x-ui.button>
            @else
                <span class="overview-value">Uçuş seçilmedi</span>
                <x-ui.button variant="secondary" size="sm" icon="search" :href="route('flights.search', ['tripRequestId' => $tripRequestId])">Uçuş ara</x-ui.button>
            @endif
        </li>
        <li @class(['overview-card', 'is-missing' => ! $plan['stay']])>
            <x-ui.icon name="bed" />
            <span class="overview-label">Konaklama</span>
            @if ($plan['stay'])
                <span class="overview-value">{{ $plan['stay']['name'] }}</span>
                <span class="overview-label">{{ $plan['stay']['detail'] }}</span>
                <span class="overview-label">{{ $plan['stay']['cancellation'] }}</span>
                <x-ui.badge :variant="['current' => 'success', 'reconfirm' => 'warning', 'expired' => 'error'][$plan['stay']['state']]">{{ $plan['stay']['state_label'] }}</x-ui.badge>
                <span class="overview-label">{{ $plan['stay']['verified'] }}</span>
                <span class="overview-label">{{ $plan['stay']['expires'] }}</span>
                <x-ui.button variant="secondary" size="sm" data-dialog-open="add-stay">{{ $plan['stay']['state'] === 'reconfirm' ? 'Konaklamayı yeniden onayla' : ($plan['stay']['state'] === 'expired' ? 'Yeni konaklama ara' : 'Konaklama seçimini yönet') }}</x-ui.button>
            @else
                <span class="overview-value">Henüz seçilmedi</span>
                <x-ui.button variant="secondary" size="sm" icon="plus" data-dialog-open="add-stay">Konaklama ekle</x-ui.button>
            @endif
        </li>
    </ul>

    @if ($planAccess === 'OWNER' && $plan['flight'])
        <section class="stack" aria-labelledby="flight-opportunity-title">
            <h2 id="flight-opportunity-title">Seçili uçuş bütçene uyuyor mu?</h2>
            <form class="stack" data-flight-opportunity action="{{ route('web-api.flight-opportunity', ['tripRequestId' => $tripRequestId]) }}" method="get">
                <label for="flight-threshold">Uçuş için üst sınırın</label>
                <input id="flight-threshold" name="threshold_amount" type="text" inputmode="decimal" required autocomplete="off" aria-describedby="flight-threshold-help">
                <p id="flight-threshold-help" class="summary-meta">Tutarı binlik ayırıcı kullanmadan girin; kuruş için virgül veya nokta kullanabilirsiniz.</p>
                <label for="flight-threshold-currency">Para birimi (üç harfli kod)</label>
                <input id="flight-threshold-currency" name="currency" type="text" minlength="3" maxlength="3" pattern="[A-Za-z]{3}" value="{{ $plan['flight']['currency'] ?? '' }}" required autocomplete="off">
                <button type="submit" class="btn btn-primary" disabled>Seçili uçuşla karşılaştır</button>
                <p class="status-text" data-opportunity-result role="status" aria-live="polite" aria-atomic="true"></p>
                <p class="summary-meta">Sonuç seçili uçuşun son doğrulanmış fiyatına dayanır. Yeni arama, fiyat garantisi, rezervasyon veya bildirim aboneliği oluşturmaz.</p>
                <noscript>Karşılaştırmayı kullanmak için JavaScript'i etkinleştirin.</noscript>
            </form>
        </section>
    @endif

    <div class="plan-layout">
        <section aria-labelledby="itinerary-title">
            <x-ui.section-header id="itinerary-title" title="Gün gün plan" />
            <div class="plan-actions">
                @foreach ($actions as $type => $action)
                    <x-ui.button :variant="$type === 'stay' && ! $plan['stay'] ? 'primary' : 'secondary'" icon="plus" data-dialog-open="add-{{ $type }}">{{ $action['label'] }}</x-ui.button>
                @endforeach
            </div>

            {{-- v3.16 unscheduled FLEXIBLE POI items (TRIP-BLD-001, OD-027). Only the owner sees the items; no day, time or
                 feasibility is shown or implied (DAY-001). Facts are the canonical snapshot with their uncertainty. --}}
            <section id="plan-pois" class="stack" aria-labelledby="plan-pois-title">
                <h3 id="plan-pois-title">Plandaki gezilecek yerler</h3>
                @if (session('plan_status'))
                    <p class="alert alert-success" role="status">{{ session('plan_status') }}</p>
                @endif
                @if (session('plan_error'))
                    <p class="alert alert-warning" role="alert">{{ session('plan_error') }}</p>
                @endif
                @if ($planAccess === 'OWNER')
                    <p class="summary-meta">Yerleri elle bir güne atayıp gün içinde sıralayabilir, kullanıcı tarafından sağlanan doğrulanmamış ziyaret süresini kaydedebilirsiniz. Saat, ulaşım ve uygulanabilirlik değerlendirmesi yapılmaz.</p>
                    {{-- FR-TRIP-BLD-001-02: the CURRENT revision's stored diff; earlier revisions stay unchanged on the server. --}}
                    @if ($poiPlan['change'])
                        @php $change = $poiPlan['change']; @endphp
                        <div class="plan-revision" data-plan-revision="{{ $change['version'] }}">
                            <h4>Son plan değişikliği</h4>
                            <ul class="facts">
                                <li class="fact">Plan sürümü {{ $change['version'] }}{{ $change['base_version'] === null ? ' (ilk sürüm)' : ' (sürüm '.$change['base_version'].' üzerine)' }} · {{ $change['created_at'] }}</li>
                                @if ($change['added'] !== [])
                                    <li class="fact">Eklendi: {{ implode(', ', $change['added']) }}</li>
                                @endif
                                @if ($change['moved'] !== [])
                                    <li class="fact">Günü veya sırası değişti: {{ implode(', ', $change['moved']) }}</li>
                                @endif
                                @if ($change['updated'] !== [])
                                    <li class="fact">Ziyaret süresi değişti: {{ implode(', ', $change['updated']) }}</li>
                                @endif
                                @if ($change['removed_count'] > 0)
                                    <li class="fact">Çıkarıldı: {{ $change['removed_count'] }} gezilecek yer</li>
                                @endif
                                <li class="fact">Gerekçe: {{ $change['reason'] }}</li>
                            </ul>
                            @if ($change['base_version'] !== null)
                                <p class="summary-meta">Önceki sürüm (sürüm {{ $change['base_version'] }}) değiştirilmeden saklanır.</p>
                            @endif
                        </div>
                    @endif
                    @if ($poiPlan['items'] === [])
                        <p class="empty-note">Plana henüz gezilecek yer veya etkinlik eklenmedi. Aşağıdaki keşif alanlarından arayıp ekleyebilirsiniz.</p>
                    @else
                        {{-- v3.18 DAY-001 (OD-029 A): day_frame days with their manually ordered items, then items without a day. --}}
                        <div class="stack" data-plan-days data-plan-version="{{ $poiPlan['version'] }}">
                            <p class="status-text" data-plan-days-status role="status" aria-live="polite" aria-atomic="true" tabindex="-1"></p>
                            <button type="button" class="btn btn-secondary" data-plan-days-reload hidden>Güncel planı yükle</button>
                            @if ($poiPlan['frame']['available'])
                                <p class="summary-meta">Günler {{ $poiPlan['frame']['timezone'] }} saat dilimindeki yerel tarihlerdir (gidiş ve dönüş günleri dahil).</p>
                                @foreach ($poiPlan['days'] as $day)
                                    <section class="stack" aria-labelledby="plan-day-{{ $day['date'] }}" data-plan-day="{{ $day['date'] }}">
                                        <h4 id="plan-day-{{ $day['date'] }}">{{ $day['label'] }}</h4>
                                        @if ($day['items'] === [])
                                            <p class="empty-note">Bu güne henüz plan öğesi atanmadı.</p>
                                        @else
                                            <ol class="offer-list">
                                                @foreach ($day['items'] as $item)
                                                    @include('partials.plan-item', ['dayOptions' => $poiPlan['days']])
                                                @endforeach
                                            </ol>
                                        @endif
                                    </section>
                                @endforeach
                            @else
                                <p class="alert alert-info" data-plan-days-unavailable>{{ $poiPlan['frame']['message'] }}</p>
                            @endif
                            <section class="stack" aria-labelledby="plan-unassigned-title" data-plan-unassigned>
                                <h4 id="plan-unassigned-title">Güne atanmamış plan öğeleri</h4>
                                @if ($poiPlan['unassigned'] === [])
                                    <p class="empty-note">Güne atanmamış plan öğesi yok.</p>
                                @else
                                    <ul class="offer-list">
                                        @foreach ($poiPlan['unassigned'] as $item)
                                            @include('partials.plan-item', ['dayOptions' => $poiPlan['days']])
                                        @endforeach
                                    </ul>
                                @endif
                            </section>
                        </div>
                        <p class="summary-meta">© <a href="https://www.openstreetmap.org/copyright" rel="noopener noreferrer" target="_blank">OpenStreetMap katkıda bulunanlar</a></p>
                    @endif
                @elseif ($planAccess === 'SIGNED_OUT')
                    <p class="alert alert-info">Plandaki gezilecek yerleri görmek ve düzenlemek için <a href="{{ route('auth.login', ['donus' => '/planlar/'.$tripRequestId.'/plan']) }}">giriş yapın</a>.</p>
                @else
                    <p class="alert alert-warning">Bu planın gezilecek yerlerini yalnız plan sahibi görüntüleyip düzenleyebilir.</p>
                @endif
            </section>

            @if ($plan['days'] === [])
                {{-- The owner's day plan is shown above once the TripRequest has plan days (v3.18). --}}
                @unless ($poiPlan['frame']['available'] ?? false)
                <div class="day-empty">
                    <p>Gün gün planınız henüz oluşturulmadı. Konaklama, gezilecek yer veya etkinlik ekleyerek başlayın.</p>
                    <div class="cluster">
                        <x-ui.button variant="secondary" icon="plus" data-dialog-open="add-sight">Gezilecek yer ekle</x-ui.button>
                        <x-ui.button variant="ghost" icon="plus" data-dialog-open="add-event">Etkinlik ekle</x-ui.button>
                    </div>
                </div>
                @endunless
            @else
            <ol class="day-list">
                @foreach ($plan['days'] as $day)
                    <li aria-labelledby="day-{{ $loop->index }}">
                        <div class="day-heading">
                            <h3 id="day-{{ $loop->index }}">{{ $day['label'] }}</h3>
                            <span>{{ $day['date'] }}</span>
                        </div>

                        @if ($day['items'] === [])
                            <div class="day-empty">
                                <p>Bu gün için henüz bir plan yok. Gezilecek bir yer ya da bir etkinlik ekleyerek başlayın.</p>
                                <div class="cluster">
                                    <x-ui.button variant="secondary" icon="plus" data-dialog-open="add-sight">Gezilecek yer ekle</x-ui.button>
                                    <x-ui.button variant="ghost" icon="plus" data-dialog-open="add-event">Etkinlik ekle</x-ui.button>
                                </div>
                            </div>
                        @else
                            <ul class="plan-items">
                                @foreach ($day['items'] as $item)
                                    @php $type = $types[$item['type']]; @endphp
                                    @if ($item['type'] === 'stay' && $item['title'] === null)
                                        <li class="plan-item plan-item-missing plan-item-stay">
                                            <span class="plan-item-time"></span>
                                            <span class="plan-item-icon"><x-ui.icon :name="$type['icon']" /></span>
                                            <div class="plan-item-body">
                                                <span class="plan-item-title">Bu gece için konaklama seçilmedi</span>
                                                <x-ui.button variant="ghost" size="sm" icon="plus" data-dialog-open="add-stay">Konaklama ekle</x-ui.button>
                                            </div>
                                        </li>
                                    @else
                                        <li class="plan-item plan-item-{{ $item['type'] }}">
                                            <span class="plan-item-time">{{ $item['time'] }}</span>
                                            <span class="plan-item-icon"><x-ui.icon :name="$type['icon']" /></span>
                                            <div class="plan-item-body">
                                                <span class="plan-item-title"><span class="visually-hidden">{{ $type['label'] }}: </span>{{ $item['title'] }}</span>
                                                @if ($item['meta'] ?? null)<span class="plan-item-meta">{{ $item['meta'] }}</span>@endif
                                            </div>
                                        </li>
                                    @endif
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ol>
            @endif
        </section>

        <aside aria-labelledby="cost-title">
            <div class="cost-summary">
                <h2 id="cost-title">Tahmini toplam</h2>
                <div class="cost-total">
                    @if ($allCostsKnown)
                        <span class="cost-total-value">{{ $plan['known_total'] }}</span>
                    @else
                        <span class="cost-total-value is-unknown">{{ $unknown }}</span>
                        @if ($plan['known_total'])
                            <span class="cost-total-label">Şu ana kadar bilinen: {{ $plan['known_total'] }}</span>
                        @endif
                    @endif
                </div>
                <dl class="cost-list">
                    @foreach ($costs as $key => $label)
                        <div>
                            <dt>{{ $label }}</dt>
                            <dd @class(['is-unknown' => $plan['costs'][$key] === null])>{{ $plan['costs'][$key] ?? ($plan['cost_notes'][$key] ?? $unknown) }}</dd>
                        </div>
                    @endforeach
                </dl>
                <p class="cost-note">Toplam, tüm kalemlerin fiyatı belli olduğunda hesaplanır.</p>
            </div>
        </aside>
    </div>

    <nav class="context-links" aria-label="Planla ilgili diğer seçenekler">
        @if ($planAccess === 'OWNER')
            <a href="{{ route('traveler-screens.index', ['tripRequestId' => $tripRequestId]) }}">Yolcularım</a>
        @endif
        <a href="{{ route('ground-connections.show', ['tripRequestId' => $tripRequestId]) }}">Kara bağlantıları</a>
        <a href="{{ route('journey.category', ['categoryId' => 'gunluk-plan']) }}">Günlük plan araçları</a>
        <a href="{{ route('journey.category', ['categoryId' => 'hazirlik-yolculuk']) }}">Hazırlık ve yolculuk</a>
    </nav>

    @foreach ($actions as $type => $action)
        <x-ui.drawer id="add-{{ $type }}" :title="$action['label']">
            @if ($type === 'stay')
                {{-- ACC-001 v3.14: accommodation.js drives search, revalidation and selection through the API only. --}}
                <div
                    class="stack"
                    data-accommodation
                    data-trip-request-id="{{ $tripRequestId }}"
                    data-start-url="{{ route('web-api.accommodation-search-jobs.store') }}"
                    data-poll-url="{{ route('web-api.accommodation-search-jobs.show', ['searchId' => '__SEARCH__']) }}"
                    data-revalidate-url="{{ route('accommodation-offers.revalidate', ['offerId' => '__OFFER__']) }}"
                    data-selection-url="{{ route('web-api.accommodation-selection.show', ['tripRequestId' => $tripRequestId]) }}"
                    data-reconfirm-url="{{ route('web-api.accommodation-selection.reconfirm', ['tripRequestId' => $tripRequestId]) }}"
                    data-plan-url="{{ route('trip-plan.show', ['tripRequestId' => $tripRequestId]) }}"
                    @if ($pollIntervalMs) data-poll-interval="{{ $pollIntervalMs }}" @endif
                >
                    <div data-accommodation-selection hidden></div>
                    <p class="summary-meta">Konaklama, seyahat talebinizin varış şehri, gidiş-dönüş tarihleri, yetişkin sayısı ve oda sayısıyla aranır. Rezervasyon yapılmaz.</p>
                    <div class="cluster">
                        <x-ui.button icon="search" data-accommodation-search>Konaklama ara</x-ui.button>
                        <x-ui.button variant="secondary" data-accommodation-refresh hidden>Durumu yenile</x-ui.button>
                    </div>
                    <p class="status-text" data-accommodation-status role="status" aria-live="polite"></p>
                    <div data-accommodation-results></div>
                </div>
            @elseif ($type === 'sight')
                {{-- POI-001 v3.15: poi.js drives discovery, detail and information refresh through the API. v3.16: the owner adds a
                     POI to the plan through a CSRF form (TripPlanWebController); no day/time (DAY-001). OSM attribution (OD-025)
                     accompanies every surface showing POI data. --}}
                <div
                    class="stack"
                    data-poi
                    data-trip-request-id="{{ $tripRequestId }}"
                    data-start-url="{{ route('web-api.poi-discovery-jobs.store') }}"
                    data-poll-url="{{ route('web-api.poi-discovery-jobs.show', ['searchId' => '__SEARCH__']) }}"
                    data-poi-url="{{ route('pois.show', ['poiId' => '__POI__']) }}"
                    data-verify-url="{{ route('pois.verify', ['poiId' => '__POI__']) }}"
                    data-attribution-url="https://www.openstreetmap.org/copyright"
                    data-plan-access="{{ $planAccess }}"
                    data-login-url="{{ route('auth.login', ['donus' => '/planlar/'.$tripRequestId.'/plan']) }}"
                    @if ($planAccess === 'OWNER')
                        data-plan-add-url="{{ route('trip-plan.pois.store', ['tripRequestId' => $tripRequestId]) }}"
                        data-plan-version="{{ $poiPlan['version'] }}"
                    @endif
                    @if ($pollIntervalMs) data-poll-interval="{{ $pollIntervalMs }}" @endif
                >
                    <p class="summary-meta">Gezilecek yerler, seyahat talebinizin varış şehrinin çevresinde aranır. Bilgiler topluluk kaynaklıdır; çalışma saati, giriş ücreti ve rezervasyon bilgisini ziyaret öncesinde kontrol edin.</p>
                    @if ($planAccess === 'OWNER')
                        <p class="alert alert-info">Eklediğiniz yerler plana esnek olarak, gün ve saat atanmadan girer.</p>
                    @elseif ($planAccess === 'SIGNED_OUT')
                        <p class="alert alert-info">Yerleri plana eklemek için giriş yapın; burada yerleri bulup inceleyebilirsiniz.</p>
                    @else
                        <p class="alert alert-info">Bu planı yalnız sahibi düzenleyebilir; burada yerleri bulup inceleyebilirsiniz.</p>
                    @endif
                    <div class="cluster">
                        <x-ui.button icon="search" data-poi-search>Gezilecek yer ara</x-ui.button>
                        <x-ui.button variant="secondary" data-poi-refresh hidden>Durumu yenile</x-ui.button>
                    </div>
                    <p class="status-text" data-poi-status role="status" aria-live="polite"></p>
                    <div data-poi-results></div>
                </div>
            @elseif ($type === 'event')
                {{-- EVT-001 v3.22: event.js drives discovery and add-to-plan through the web-api channel (OD-028). --}}
                <div
                    class="stack"
                    data-event
                    data-trip-request-id="{{ $tripRequestId }}"
                    data-start-url="{{ route('web-api.event-discovery-jobs.store') }}"
                    data-poll-url="{{ route('web-api.event-discovery-jobs.show', ['searchId' => '__SEARCH__']) }}"
                    data-plan-access="{{ $planAccess }}"
                    data-login-url="{{ route('auth.login', ['donus' => '/planlar/'.$tripRequestId.'/plan']) }}"
                    @if ($planAccess === 'OWNER')
                        data-plan-add-url="{{ route('trip-plan.events.store', ['tripRequestId' => $tripRequestId]) }}"
                        data-plan-version="{{ $poiPlan['version'] }}"
                    @endif
                    @if ($pollIntervalMs) data-poll-interval="{{ $pollIntervalMs }}" @endif
                >
                    <p class="summary-meta">Etkinlikler, seyahat talebinizin varış şehrinde gidiş-dönüş tarihleri arasında aranır. Bilet satın alma işlemi burada yapılmaz; bilet sayfasına yönlendirilirsiniz.</p>
                    @if ($planAccess === 'OWNER')
                        <p class="alert alert-info">Eklediğiniz etkinlikler plana sabit zaman kısıtlamasıyla girer.</p>
                    @elseif ($planAccess === 'SIGNED_OUT')
                        <p class="alert alert-info">Etkinlikleri plana eklemek için giriş yapın; burada etkinlikleri bulup inceleyebilirsiniz.</p>
                    @else
                        <p class="alert alert-info">Bu planı yalnız sahibi düzenleyebilir; burada etkinlikleri bulup inceleyebilirsiniz.</p>
                    @endif
                    <div class="cluster">
                        <x-ui.button icon="search" data-event-search>Etkinlik ara</x-ui.button>
                        <x-ui.button variant="secondary" data-event-refresh hidden>Durumu yenile</x-ui.button>
                    </div>
                    <p class="status-text" data-event-status role="status" aria-live="polite"></p>
                    <div data-event-results></div>
                </div>
            @else
                <x-ui.empty-state title="Öneriler hazırlanıyor" :icon="$types[$type]['icon']" :level="3">{{ $action['empty'] }}</x-ui.empty-state>
            @endif
            <x-slot:footer>
                <x-ui.button variant="secondary" data-dialog-close>Kapat</x-ui.button>
            </x-slot:footer>
        </x-ui.drawer>
    @endforeach
@endsection
