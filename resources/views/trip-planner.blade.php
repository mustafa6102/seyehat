@extends('layouts.app')

@section('description', 'Başlangıç, varış ve tarih bilgilerinizi girerek seyahat talebinizi oluşturun.')
@section('main-class', 'container page')

@push('scripts')
    <script src="{{ asset('assets/trip-planner.js') }}?v={{ @filemtime(public_path('assets/trip-planner.js')) }}" defer></script>
@endpush

@section('content')
    @php
        $journeyCategories = config('frontend_screens.categories');
    @endphp
    <section class="home-dashboard" aria-labelledby="home-title">
        <div class="home-dashboard-copy">
            <p class="eyebrow">Seyahat merkezi</p>
            <h1 id="home-title">Planınıza kaldığınız yerden devam edin</h1>
            <p>Yeni bir rota oluşturun, mevcut seyahatinizi açın veya yolculuğun her adımında hangi seçeneklerin bulunduğunu keşfedin.</p>
            <div class="home-actions">
                <a class="btn btn-secondary" href="#yeni-plan">Yeni plan oluştur</a>
                <a class="btn btn-secondary" href="{{ route('trip-requests.mine') }}">Seyahatlerime git</a>
            </div>
        </div>

        <div class="home-recent" aria-labelledby="recent-title">
            <h2 id="recent-title">Son seyahatler</h2>
            @if (($recentTrips ?? []) === [])
                <p>Bu oturumda henüz bir seyahat yok. Aşağıdaki formdan ilk planınızı başlatabilirsiniz.</p>
            @else
                <ul class="home-recent-list">
                    @foreach ($recentTrips as $recentTrip)
                        <li>
                            <a href="{{ route('trip-plan.show', ['tripRequestId' => $recentTrip['id']]) }}">
                                <strong>{{ $recentTrip['origin'] }} → {{ $recentTrip['destination'] }}</strong>
                                <span>{{ $recentTrip['status'] === 'SEARCHABLE' ? 'Planlamaya hazır' : 'Taslak' }} · Plana devam et</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </section>

    <section class="journey-shortcuts" aria-labelledby="journey-shortcuts-title">
        <div class="section-heading-row">
            <div>
                <p class="eyebrow">Yolculuğun her adımı</p>
                <h2 id="journey-shortcuts-title">Ne yapmak istiyorsunuz?</h2>
            </div>
            <a class="btn btn-link" href="{{ route('journey.index') }}">Tüm yolculuk rehberi</a>
        </div>
        <ul class="journey-category-grid">
            @foreach ($journeyCategories as $categoryId => $category)
                <li>
                    <a class="journey-category-card" href="{{ route('journey.category', ['categoryId' => $categoryId]) }}">
                        <span class="icon-tile"><x-ui.icon :name="$category['icon']" /></span>
                        <strong>{{ $category['title'] }}</strong>
                        <span>{{ $category['description'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </section>

    <div id="yeni-plan" class="anchor-target"></div>
    @include('partials.flow-steps', ['current' => 'request'])

    <section class="intro" aria-labelledby="new-plan-title">
        <h2 id="new-plan-title">Yolculuğunuzu planlayın</h2>
        <p>Nereden nereye ve ne zaman gideceğinizi söyleyin; uçuş seçeneklerini sizin için arayalım.</p>
    </section>

    <section class="planner-panel" aria-labelledby="new-plan-title">
        @if ($errors->any())
            <div class="error-summary" role="alert" tabindex="-1" data-error-summary>
                <h2>Bilgileri kontrol edin</h2>
                <ul>
                    @foreach (array_unique($errors->all()) as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form id="planner-form" class="planner-form" method="post" action="{{ route('trip-planner.store') }}" novalidate>
            @csrf

            <p id="location-catalog-status" class="catalog-notice" data-location-catalog-status @if ($locationSearchState !== 'unavailable') hidden @endif>
                Ülkeyi seçebilirsiniz; ancak şehir ve havalimanı araması şu anda kullanılamıyor. Arama açıldığında konumları buradan seçebileceksiniz.
            </p>

            <div
                class="route-fields"
                data-location-kinds='@json($locationKinds)'
                data-location-countries-url="{{ route('locations.countries') }}"
                data-location-resolve-url="{{ route('locations.resolve') }}"
                @if ($locationSearchState === 'flight')
                    data-flight-location-search-url="{{ route('locations.search', ['baglam' => 'ucus']) }}"
                @elseif ($locationSearchState === 'general')
                    data-location-search-url="{{ route('locations.search') }}"
                @endif
            >
                @include('partials.location-picker', [
                    'prefix' => 'origin',
                    'legend' => 'Nereden?',
                    'help' => 'Ülkeyi seçin, ardından şehir, havaalanı veya istasyonu arayıp listeden seçin.',
                    'flightHelp' => 'Kalkış ülkesini seçin, ardından havalimanını veya şehri arayıp listeden seçin.',
                    'flightMode' => $locationSearchState === 'flight',
                    'placeLabel' => 'Kalkış',
                ])

                @include('partials.location-picker', [
                    'prefix' => 'destination',
                    'legend' => 'Nereye?',
                    'help' => 'Varış ülkesini seçin, ardından varış noktasını arayıp listeden seçin.',
                    'flightHelp' => 'Varış ülkesini seçin, ardından havalimanını veya şehri arayıp listeden seçin.',
                    'flightMode' => $locationSearchState === 'flight',
                    'placeLabel' => 'Varış',
                ])
            </div>

            @if ($locationSearchState === 'general')
                <p class="data-attribution">Konum verileri: <a href="https://www.geonames.org/" rel="noopener">GeoNames</a> (<a href="https://creativecommons.org/licenses/by/4.0/deed.tr" rel="noopener">CC BY 4.0</a>)</p>
            @endif

            @php
                $journeyType = old('journey_type');
            @endphp
            {{-- data-journey wraps the journey choice and both dates so the script can show/hide the return date. --}}
            <div class="form-section schedule" data-journey>
                <fieldset class="choice-group">
                    <legend>Yolculuk tipi</legend>
                    <div class="segmented" role="radiogroup" aria-label="Yolculuk tipi" @error('journey_type') aria-describedby="journey-type-error" @enderror>
                        <label class="choice"><input type="radio" name="journey_type" value="ONE_WAY" @checked($journeyType === 'ONE_WAY') @error('journey_type') aria-invalid="true" @enderror><span>Tek yön</span></label>
                        <label class="choice"><input type="radio" name="journey_type" value="ROUND_TRIP" @checked($journeyType === 'ROUND_TRIP') @error('journey_type') aria-invalid="true" @enderror><span>Gidiş-dönüş</span></label>
                    </div>
                    @error('journey_type') <span id="journey-type-error" class="field-error">{{ $message }}</span> @enderror
                </fieldset>

                <div class="date-grid">
                    @include('partials.travel-date', ['prefix' => 'outbound', 'legend' => 'Gidiş', 'enabled' => true])

                    {{-- Return date exists only for Gidiş-dönüş; otherwise it is hidden, disabled and never submitted. --}}
                    <div data-return-date @if ($journeyType !== 'ROUND_TRIP') hidden @endif>
                        @include('partials.travel-date', ['prefix' => 'return', 'legend' => 'Dönüş', 'enabled' => $journeyType === 'ROUND_TRIP'])
                    </div>
                </div>
            </div>

            @php
                $counts = ['adults' => old('adults', 1), 'children' => old('children', 0), 'infants' => old('infants', 0)];
                $passengerError = $errors->first('adults') ?: $errors->first('children') ?: $errors->first('infants') ?: $errors->first('traveler_count');
            @endphp
            <x-ui.passenger-selector
                :counts="$counts"
                :traveler-count="old('traveler_count', 1)"
                :error="$passengerError ?: null"
                :invalid="array_values(array_filter(array_keys($counts), fn ($field) => $errors->has($field)))"
            >
                {{-- v3.14: explicit room count for accommodation search; 1 is only the form's starting value. --}}
                <div class="room-field">
                    <label for="room-count">Oda</label>
                    <input id="room-count" type="number" name="room_count" min="1" step="1" inputmode="numeric" value="{{ old('room_count', 1) }}" aria-describedby="room-count-hint @error('room_count') room-count-error @enderror" @error('room_count') aria-invalid="true" @enderror>
                    <small id="room-count-hint">Konaklama araması bu oda sayısıyla yapılır.</small>
                    @error('room_count') <span id="room-count-error" class="field-error">{{ $message }}</span> @enderror
                </div>
            </x-ui.passenger-selector>

            <details class="form-section optional-section" @if (old('pace_preference') || $errors->has('pace_preference')) open @endif>
                <summary>Tempo tercihi <span class="optional">İsteğe bağlı</span></summary>
                <label>
                    <span class="visually-hidden">Tempo tercihi</span>
                    <input name="pace_preference" value="{{ old('pace_preference') }}" placeholder="Örn. dengeli" @error('pace_preference') aria-invalid="true" @enderror>
                </label>
                @error('pace_preference') <span class="field-error">{{ $message }}</span> @enderror
            </details>

            @unless ($signedIn)
                <p class="alert alert-info" data-sign-in-required>Seyahat talebi hesabınıza kaydedilir. Formu doldurmadan önce <a href="{{ route('auth.login', ['donus' => '/']) }}">giriş yapın</a>; gönderim sırasında girişe yönlendirilirseniz bilgiler korunmaz.</p>
            @endunless

            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-cta">Seyahat talebi oluştur</button>
            </div>
        </form>
    </section>
@endsection
