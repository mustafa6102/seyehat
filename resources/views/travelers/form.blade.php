@extends('layouts.app')

@php
    $heading = $slot === null ? 'Yolcu bilgisi ekle' : $slot['label'].' bilgilerini düzenle';
    $minor = $slot['minor'] ?? ($slots[0]['minor'] ?? false);
    $cancelUrl = $slot === null
        ? route('traveler-screens.index', ['tripRequestId' => $tripRequestId])
        : route('traveler-screens.show', ['tripRequestId' => $tripRequestId, 'slot' => $slot['number']]);
@endphp

@section('title', $heading)
@section('description', 'Yolcu profili alanlarını görün; kaydetme işlemi onaya bağlıdır.')

@push('scripts')
    <script src="{{ asset('assets/travelers.js') }}?v={{ @filemtime(public_path('assets/travelers.js')) }}" defer></script>
@endpush

@section('content')
    @include('travelers.partials.breadcrumb', ['current' => $heading])

    <header class="page-header">
        <div>
            <p class="eyebrow">{{ $origin }} → {{ $destination }}</p>
            <h1 class="page-title">{{ $heading }}</h1>
            <p class="page-lead">Yalnız seyahat hazırlığı için gereken alanlar gösterilir.</p>
        </div>
    </header>

    @include('travelers.partials.restricted')

    <form class="card traveler-form" autocomplete="off" data-traveler-form data-always-missing="Yaş kategorisi" aria-labelledby="profile-form-title">
        <h2 class="card-title" id="profile-form-title">Profil alanları</h2>

        @if ($slot === null)
            <div class="field">
                <label class="field-label" for="traveler-slot">Yolcu</label>
                <select id="traveler-slot" data-traveler-slot>
                    @foreach ($slots as $option)
                        <option value="{{ $option['number'] }}" data-minor="{{ $option['minor'] ? '1' : '0' }}">{{ $option['label'] }} · {{ $option['typeLabel'] }}</option>
                    @endforeach
                </select>
            </div>
        @else
            <p class="request-facts"><span>Yolcu tipi (talepten): {{ $slot['typeLabel'] }}</span></p>
        @endif

        @include('travelers.partials.form-controls')

        <fieldset class="stack" disabled data-traveler-fields>
            <legend class="visually-hidden">Profil bilgileri</legend>
            <div class="field">
                <label class="field-label" for="age-category">Yaş kategorisi</label>
                <select id="age-category" disabled data-traveler-locked aria-describedby="age-category-hint">
                    <option>Onaylı liste bekleniyor</option>
                </select>
                <small id="age-category-hint">Yaş kategorisi listesi henüz onaylanmadı; bu nedenle seçim yapılamaz.</small>
            </div>

            <div class="traveler-repeat" data-repeat data-repeat-label="Vatandaşlık">
                <h3 class="traveler-repeat-title">Vatandaşlık</h3>
                <p class="field-hint" data-repeat-empty>Vatandaşlık eklenmedi. Çift vatandaşlık için birden fazla satır ekleyebilirsiniz.</p>
                <ul class="traveler-repeat-list" data-repeat-list></ul>
                <template data-repeat-template>
                    <li class="traveler-repeat-row">
                        <div class="field">
                            <label class="field-label">Vatandaşlık ülkesi
                                <input type="text" autocomplete="off" maxlength="80" data-repeat-required>
                            </label>
                        </div>
                        <button type="button" class="btn btn-link" data-repeat-remove>Kaldır</button>
                    </li>
                </template>
                <button type="button" class="btn btn-secondary btn-sm" data-repeat-add>Vatandaşlık ekle</button>
            </div>
        </fieldset>

        @include('travelers.partials.form-actions')
    </form>

    @include('travelers.partials.access-rule')
@endsection
