@extends('layouts.app')

@section('title', $notice['title'])
@section('description', 'Yolcu profili bilgilerinin işlenmesine ilişkin aydınlatma metni taslağı.')

@section('content')
    <header class="page-header">
        <div>
            <p class="eyebrow">Gizlilik</p>
            <h1 class="page-title">{{ $notice['title'] }}</h1>
            <p class="page-lead">Bu metin bilgilendirme amaçlıdır; okumak için onay vermeniz gerekmez ve açık rızanın yerine geçmez.</p>
        </div>
        <x-ui.badge variant="unverified">Taslak · {{ $notice['version'] }}</x-ui.badge>
    </header>

    @include('partials.legal-status')

    <section class="card legal-text" aria-label="Aydınlatma metni">
        @foreach ($notice['notice'] as $paragraph)
            <p>@include('partials.legal-text', ['segments' => $paragraph])</p>
        @endforeach
    </section>

    @isset($notice['contact_email'])
        <section class="card" aria-labelledby="request-channel-title">
            <h2 class="card-title" id="request-channel-title">Başvuru kanalı</h2>
            <p>Başvuru için hesabınızın olması gerekmez: <a href="mailto:{{ $notice['contact_email'] }}">{{ $notice['contact_email'] }}</a></p>
        </section>
    @endisset

    <section class="card" aria-labelledby="consent-link-title">
        <h2 class="card-title" id="consent-link-title">Açık rıza ayrı bir işlemdir</h2>
        <p>Açık rıza gerektiren isteğe bağlı bir işleme varsa ayrı ekranda sunulur.</p>
        <a class="btn btn-secondary" href="{{ route('privacy.traveler-consent') }}">Açık rıza ekranını gör</a>
    </section>

    <section class="card" aria-labelledby="sources-title">
        <h2 class="card-title" id="sources-title">Resmî kaynaklar</h2>
        <ul>
            @foreach ($notice['sources'] as $source)
                <li><a href="{{ $source['url'] }}" rel="noopener noreferrer" target="_blank">{{ $source['label'] }}</a></li>
            @endforeach
        </ul>
        <p class="field-hint">Gösterim kaydı henüz oluşturulmuyor; bu işlem onaylı sözleşmeyle etkinleşir.</p>
    </section>
@endsection
