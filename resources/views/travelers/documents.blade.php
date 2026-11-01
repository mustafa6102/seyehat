@extends('layouts.app')

@php
    $minor = $slot['minor'];
    $cancelUrl = route('traveler-screens.show', ['tripRequestId' => $tripRequestId, 'slot' => $slot['number']]);
    // SCH-Traveler: a document has document_type; visas and permits carry only the derived verification status.
    $groups = [
        ['label' => 'Seyahat belgesi', 'add' => 'Seyahat belgesi ekle', 'typed' => true],
        ['label' => 'Vize', 'add' => 'Vize ekle', 'typed' => false],
        ['label' => 'Oturum izni', 'add' => 'Oturum izni ekle', 'typed' => false],
    ];
@endphp

@section('title', $slot['label'].' belgeleri')
@section('description', 'Yolcunun seyahat belgesi, vize ve oturum izni bölümleri.')

@push('scripts')
    <script src="{{ asset('assets/travelers.js') }}?v={{ @filemtime(public_path('assets/travelers.js')) }}" defer></script>
@endpush

@section('content')
    @include('travelers.partials.breadcrumb', ['current' => $slot['label'].' belgeleri'])

    <header class="page-header">
        <div>
            <p class="eyebrow">{{ $origin }} → {{ $destination }}</p>
            <h1 class="page-title">{{ $slot['label'] }} belgeleri</h1>
            <p class="page-lead">{{ $slot['typeLabel'] }} · Seyahat belgesi, vize ve oturum izni bölümleri ayrı tutulur. Belge numarası istenmez.</p>
        </div>
    </header>

    @include('travelers.partials.restricted')

    @include('travelers.partials.access-rule')

    <form class="card traveler-form" autocomplete="off" data-traveler-form aria-labelledby="documents-title">
        <h2 class="card-title" id="documents-title">Belge bölümleri</h2>

        @include('travelers.partials.form-controls')

        <fieldset class="stack" disabled data-traveler-fields>
            <legend class="visually-hidden">Belge bilgileri</legend>
            @foreach ($groups as $group)
                <div class="traveler-repeat" data-repeat data-repeat-label="{{ $group['label'] }}">
                    <h3 class="traveler-repeat-title">{{ $group['label'] }}</h3>
                    <p class="field-hint" data-repeat-empty>{{ $group['label'] }} bilgisi yok.</p>
                    <ul class="traveler-repeat-list" data-repeat-list></ul>
                    <template data-repeat-template>
                        <li class="traveler-repeat-row">
                            @if ($group['typed'])
                                <div class="field">
                                    <label class="field-label">Belge türü
                                        <input type="text" autocomplete="off" maxlength="80" data-repeat-required>
                                    </label>
                                </div>
                            @else
                                <p class="traveler-repeat-name">{{ $group['label'] }} kaydı</p>
                            @endif
                            <x-ui.badge variant="unverified">Doğrulanmadı</x-ui.badge>
                            <button type="button" class="btn btn-link" data-repeat-remove>Kaldır</button>
                        </li>
                    </template>
                    <button type="button" class="btn btn-secondary btn-sm" data-repeat-add>{{ $group['add'] }}</button>
                </div>
            @endforeach
        </fieldset>

        @include('travelers.partials.form-actions')
    </form>
@endsection
