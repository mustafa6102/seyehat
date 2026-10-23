@extends('layouts.app')

@section('title', 'Teklif ayrıntısı')

@push('scripts')
    <script src="{{ asset('assets/flight.js') }}" defer></script>
@endpush

@section('content')
    @include('partials.flow-steps', ['current' => 'offer'])

    <header class="page-header">
        <div>
            <p class="eyebrow">Teklif ayrıntısı</p>
            <h1 class="page-title">Uçuş teklifi</h1>
        </div>
        <button type="button" class="btn btn-secondary" data-history-back>Geri dön</button>
    </header>

    <div
        data-offer-detail
        data-offer-url="{{ route('offers.show', ['offerId' => $offerId]) }}"
        data-reprice-url="{{ route('offers.reprice', ['offerId' => $offerId]) }}"
        @if ($tripRequestId)
            data-selection-url="{{ route('web-api.flight-selection.show', ['tripRequestId' => $tripRequestId]) }}"
            data-reconfirm-url="{{ route('web-api.flight-selection.reconfirm', ['tripRequestId' => $tripRequestId]) }}"
            data-plan-url="{{ route('trip-plan.show', ['tripRequestId' => $tripRequestId]) }}"
        @endif
    >
        <section class="card" aria-labelledby="offer-title">
            <h2 id="offer-title" class="card-title">Teklif</h2>
            <div data-offer-body role="status" aria-live="polite">
                <p class="loading">Teklif yükleniyor…</p>
            </div>
        </section>

        <section class="card" aria-labelledby="reprice-title" data-reprice-section hidden>
            <h2 id="reprice-title" class="card-title">Güncel fiyat ve koşullar</h2>
            <p>Teklifin fiyatı, ücret koşulları ve bagaj bilgisi sayfa açıldığında sağlayıcıdan yeniden kontrol edilir.</p>
            <div data-reprice-result role="status" aria-live="polite"></div>
            <button type="button" class="btn btn-secondary" data-reprice>Yeniden kontrol et</button>
        </section>

        @if ($tripRequestId)
            {{-- v3.13: shown by flight.js only after a successful revalidation; success appears only after the server confirms. --}}
            <section class="card" aria-labelledby="selection-title" data-selection hidden>
                <h2 id="selection-title" class="card-title">Seyahat planına ekle</h2>
                <div data-selection-body></div>
                <p class="status-text" data-selection-status role="status" aria-live="polite"></p>
            </section>
        @endif

        <section class="card" aria-labelledby="continue-title">
            <h2 id="continue-title" class="card-title">Devam etme</h2>
            <div class="alert alert-info">
                <p>Rezervasyon veya partner sitesine yönlendirme adımı henüz kullanıma açılmadı. Bu nedenle bu sayfada satın alma işlemi yapılamaz.</p>
            </div>
        </section>
    </div>
@endsection
