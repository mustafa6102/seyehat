@extends('layouts.app')

@section('title', $notice['consent']['title'])
@section('description', 'Yolcu profili için isteğe bağlı açık rıza ekranı taslağı.')

@push('scripts')
    <script src="{{ asset('assets/travelers.js') }}?v={{ @filemtime(public_path('assets/travelers.js')) }}" defer></script>
@endpush

@section('content')
    <nav class="breadcrumb" aria-label="Sayfa yolu">
        <a href="{{ route('privacy.traveler-notice') }}">{{ $notice['title'] }}</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page">{{ $notice['consent']['title'] }}</span>
    </nav>

    <header class="page-header">
        <div>
            <p class="eyebrow">Gizlilik</p>
            <h1 class="page-title">{{ $notice['consent']['title'] }}</h1>
            <p class="page-lead">Açık rıza isteğe bağlıdır, aydınlatmanın yerine geçmez ve geri alınabilir. Rıza yoksa rızaya dayalı işlem çalışmaz.</p>
        </div>
        <x-ui.badge variant="unverified">Taslak · {{ $notice['version'] }}</x-ui.badge>
    </header>

    @include('partials.legal-status')

    <section class="card" aria-labelledby="consent-title" data-consent>
        <h2 class="card-title" id="consent-title">Rıza metni</h2>
        <p class="legal-text">@include('partials.legal-text', ['segments' => $notice['consent']['text']])</p>
        @if ($notice['consent']['required'] ?? true)
            <label class="check">
                <input type="checkbox" autocomplete="off" data-consent-check>
                <span>Bu metne açık rıza veriyorum</span>
            </label>
            <p class="traveler-check" role="status" data-consent-status>Rıza alınmadı.</p>
            <div class="traveler-actions">
                <button type="button" class="btn btn-primary" disabled aria-describedby="consent-restricted">Rızayı kaydet</button>
                <button type="button" class="btn btn-secondary" disabled aria-describedby="consent-restricted">Rızayı geri al</button>
            </div>
            <p class="field-hint" id="consent-restricted">Rıza kaydı işlemi onaylanmadığı için kaydetme kapalıdır. Kayıtlı rıza bulunmadığından geri alınacak bir kayıt da yoktur.</p>
        @endif
    </section>

    <section class="card" aria-labelledby="consent-record-title">
        <h2 class="card-title" id="consent-record-title">Rıza durumu</h2>
        <dl class="summary-list">
            <div><dt>Kayıtlı rıza</dt><dd>Yok</dd></div>
            <div><dt>Metin sürümü</dt><dd>{{ $notice['version'] }}</dd></div>
            <div><dt>Yurt dışı aktarım</dt><dd>Aydınlatma metninde açıklanır; açık rızaya dayanmaz.</dd></div>
        </dl>
        <p class="field-hint">Rıza kaydı ayrı tutulacak; tarih, metin sürümü, ilgili kişi, amaç ve geri alma zamanını içerecektir.</p>
    </section>
@endsection
