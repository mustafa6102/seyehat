@extends('layouts.app')

@section('title', $slot['label'].' profili')
@section('description', 'Yolcu profilinin eksik bilgi ve yetki durumunu görün.')

@section('content')
    @include('travelers.partials.breadcrumb', ['current' => $slot['label']])

    <header class="page-header">
        <div>
            <p class="eyebrow">{{ $origin }} → {{ $destination }}</p>
            <h1 class="page-title">{{ $slot['label'] }}</h1>
            <p class="page-lead">{{ $slot['typeLabel'] }} · Bilgiler onaylı işlem açılana kadar görüntülenemez.</p>
        </div>
        <div class="traveler-actions">
            <a class="btn btn-secondary" href="{{ route('traveler-screens.edit', ['tripRequestId' => $tripRequestId, 'slot' => $slot['number']]) }}">Düzenle</a>
            <a class="btn btn-primary" href="{{ route('traveler-screens.documents', ['tripRequestId' => $tripRequestId, 'slot' => $slot['number']]) }}">Belgeler</a>
        </div>
    </header>

    @include('travelers.partials.restricted')

    <section class="card" aria-labelledby="profile-title">
        <h2 class="card-title" id="profile-title">Profil özeti</h2>
        <dl class="summary-list">
            <div><dt>Yolcu tipi (talepten)</dt><dd>{{ $slot['typeLabel'] }}</dd></div>
            @if ($slot['traveler'])
                <div><dt>Yolcu kaydı</dt><dd><a href="{{ route('traveler-screens.record', ['tripRequestId' => $tripRequestId, 'travelerId' => $slot['traveler']->id]) }}">Yetişkin yolcu {{ $slot['number'] }}</a></dd></div>
            @endif
            @foreach ($fields as $field)
                <div><dt>{{ $field }}</dt><dd>Bilgi yok</dd></div>
            @endforeach
        </dl>
    </section>

    <section class="card" aria-labelledby="readiness-title">
        <h2 class="card-title" id="readiness-title">Değerlendirme durumu</h2>
        <p><x-ui.badge variant="unverified">Doğrulama gerekli</x-ui.badge></p>
        <p>Uyum değerlendirmesi için ek doğrulama gerekiyor. Eksik bilgi tahmin edilmez; belge doğrulaması bu ekranda yapılmaz.</p>
    </section>

    @include('travelers.partials.access-rule', ['minor' => $slot['minor']])
@endsection
