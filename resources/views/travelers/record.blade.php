@extends('layouts.app')

@php
    $heading = $ordinal === null ? 'Yetişkin yolcu' : 'Yetişkin yolcu '.$ordinal;
@endphp

@section('title', $heading)
@section('description', 'Eklenen yetişkin yolcunun kayıt bilgilerini görün.')

@section('content')
    @include('travelers.partials.breadcrumb', ['current' => $heading])

    <header class="page-header">
        <div>
            <p class="eyebrow">{{ $origin }} → {{ $destination }}</p>
            <h1 class="page-title">{{ $heading }}</h1>
            <p class="page-lead">Bu kayıt yalnız yolcu tipini tutar; kimlik ve belge bilgisi içermez.</p>
        </div>
    </header>

    <section class="card" aria-labelledby="record-title">
        <h2 class="card-title" id="record-title">Kayıt özeti</h2>
        <dl class="summary-list">
            <div><dt>Yolcu tipi</dt><dd>Yetişkin</dd></div>
            <div><dt>Eklenme tarihi</dt><dd>{{ \Illuminate\Support\Carbon::parse($traveler->createdAt)->format('d.m.Y') }}</dd></div>
        </dl>
    </section>

    <section class="card" aria-labelledby="subject-request-title">
        <h2 class="card-title" id="subject-request-title">Kişisel veri başvurusu</h2>

        @if (session('traveler_status'))
            <p class="alert alert-success" role="status">{{ session('traveler_status') }}</p>
        @endif

        <p>Bu kaydın silinmesini veya hakkında bilgi verilmesini isteyebilirsiniz. Başvurular yetkili kişilerce tek tek değerlendirilir.</p>
        @include('travelers.partials.notice-link')

        @foreach ([\App\Modules\Traveler\Contracts\TravelerSubjectRequests::DELETE => ['Kaydın silinmesini iste', 'Silme başvurunuz değerlendiriliyor.'], \App\Modules\Traveler\Contracts\TravelerSubjectRequests::ACCESS => ['Kayıt hakkında bilgi iste', 'Bilgi başvurunuz değerlendiriliyor.']] as $type => [$action, $pending])
            @if (in_array($type, $openRequests, true))
                <p>{{ $pending }}</p>
            @else
                <form method="post" action="{{ route('traveler-screens.subject-request', ['tripRequestId' => $tripRequestId, 'travelerId' => $traveler->id]) }}">
                    @csrf
                    <input type="hidden" name="request_type" value="{{ $type }}">
                    <button type="submit" class="btn btn-secondary">{{ $action }}</button>
                </form>
            @endif
        @endforeach
    </section>

    <a class="btn btn-secondary" href="{{ route('traveler-screens.index', ['tripRequestId' => $tripRequestId]) }}">Yolcularıma dön</a>
@endsection
