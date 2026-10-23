{{-- Seyahat Planım (TRIP-BLD-001). $plan, TripPlannerController::plan tarafından yalnız kalıcı TripRequest verisi ve
     v3.13 uçuş seçimiyle doldurulur; örnek veri yoktur. Bilinmeyen tutarlar null kalır ve "Henüz hesaplanmadı" yazılır; hiçbir zaman 0 gösterilmez. --}}
@extends('layouts.app')

@section('title', 'Seyahat Planım')

@push('scripts')
    <script src="{{ asset('assets/accommodation.js') }}?v={{ @filemtime(public_path('assets/accommodation.js')) }}" defer></script>
    <script src="{{ asset('assets/poi.js') }}?v={{ @filemtime(public_path('assets/poi.js')) }}" defer></script>
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
            @else
                <span class="overview-value">Henüz seçilmedi</span>
                <x-ui.button variant="secondary" size="sm" icon="plus" data-dialog-open="add-stay">Konaklama ekle</x-ui.button>
            @endif
        </li>
    </ul>

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
                    <p class="summary-meta">Bu yerler henüz bir güne veya saate atanmadı (esnek); ziyaret için uygunlukları değerlendirilmedi.</p>
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
                        <p class="empty-note">Plana henüz gezilecek yer eklenmedi. "Gezilecek yer ekle" ile arayıp ekleyebilirsiniz.</p>
                    @else
                        <ul class="offer-list">
                            @foreach ($poiPlan['items'] as $item)
                                <li>
                                    <article class="offer-card">
                                        <header class="offer-card-header">
                                            <div>
                                                <div class="offer-heading">
                                                    <h4 class="hotel-name">{{ $item['poi']['name'] }}</h4>
                                                    <span class="badge badge-info">Esnek · gün ve saat atanmadı</span>
                                                    @if ($item['poi']['unverified'])
                                                        <span class="badge badge-neutral">Doğrulanmamış bilgi</span>
                                                    @endif
                                                </div>
                                                <p class="offer-date">{{ $item['poi']['address'] }}</p>
                                            </div>
                                        </header>
                                        <ul class="facts">
                                            @foreach ($item['poi']['facts'] as $fact)
                                                <li class="fact">{{ $fact }}</li>
                                            @endforeach
                                            @if ($item['poi']['booking_url'])
                                                <li class="fact"><a href="{{ $item['poi']['booking_url'] }}" rel="noopener noreferrer" target="_blank">Rezervasyon sayfası</a></li>
                                            @endif
                                        </ul>
                                        <ul class="facts">
                                            @foreach ($item['poi']['hours'] as $line)
                                                <li class="fact">{{ $line }}</li>
                                            @endforeach
                                        </ul>
                                        <form method="post" action="{{ route('trip-plan.pois.destroy', ['tripRequestId' => $tripRequestId, 'itemId' => $item['item_id']]) }}" data-plan-mutation>
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="expected_version" value="{{ $poiPlan['version'] }}">
                                            <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                                            <button type="submit" class="btn btn-secondary">Plandan çıkar</button>
                                        </form>
                                    </article>
                                </li>
                            @endforeach
                        </ul>
                        <p class="summary-meta">© <a href="https://www.openstreetmap.org/copyright" rel="noopener noreferrer" target="_blank">OpenStreetMap katkıda bulunanlar</a></p>
                    @endif
                @elseif ($planAccess === 'SIGNED_OUT')
                    <p class="alert alert-info">Plandaki gezilecek yerleri görmek ve düzenlemek için <a href="{{ route('auth.login', ['donus' => '/planlar/'.$tripRequestId.'/plan']) }}">giriş yapın</a>.</p>
                @else
                    <p class="alert alert-warning">Bu planın gezilecek yerlerini yalnız plan sahibi görüntüleyip düzenleyebilir.</p>
                @endif
            </section>

            @if ($plan['days'] === [])
                <div class="day-empty">
                    <p>Gün gün planınız henüz oluşturulmadı. Konaklama, gezilecek yer veya etkinlik ekleyerek başlayın.</p>
                    <div class="cluster">
                        <x-ui.button variant="secondary" icon="plus" data-dialog-open="add-sight">Gezilecek yer ekle</x-ui.button>
                        <x-ui.button variant="ghost" icon="plus" data-dialog-open="add-event">Etkinlik ekle</x-ui.button>
                    </div>
                </div>
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
            @else
                <x-ui.empty-state title="Öneriler hazırlanıyor" :icon="$types[$type]['icon']" :level="3">{{ $action['empty'] }}</x-ui.empty-state>
            @endif
            <x-slot:footer>
                <x-ui.button variant="secondary" data-dialog-close>Kapat</x-ui.button>
            </x-slot:footer>
        </x-ui.drawer>
    @endforeach
@endsection
