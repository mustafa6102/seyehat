@extends('layouts.app')

@section('title', 'Yolcularım')
@section('description', 'Seyahatinizdeki yolcuları, profil ve belge bölümlerini görün.')

@push('scripts')
    <script src="{{ asset('assets/travelers.js') }}?v={{ @filemtime(public_path('assets/travelers.js')) }}" defer></script>
@endpush

@section('content')
    @include('travelers.partials.breadcrumb', ['current' => 'Yolcularım'])

    <header class="page-header">
        <div>
            <p class="eyebrow">{{ $origin }} → {{ $destination }}</p>
            <h1 class="page-title">Yolcularım</h1>
            <p class="page-lead">Bu seyahat talebindeki {{ count($slots) }} yolcu, talepteki yolcu sayılarından listelenir. Her yolcu ayrı değerlendirilir.</p>
        </div>
        @if ($roster === null)
            <a class="btn btn-secondary" href="{{ route('traveler-screens.create', ['tripRequestId' => $tripRequestId]) }}">Yolcu bilgisi ekle</a>
        @endif
    </header>

    @include('travelers.partials.restricted')

    @if ($roster !== null)
        @include('travelers.partials.basic-roster')
    @endif

    @if ($slots === [])
        <x-ui.empty-state title="Bu talepte yolcu bulunmuyor" icon="users">
            Yolcu sayısı seyahat talebinden gelir.
            <x-slot:actions>
                <a class="btn btn-primary" href="{{ route('trip-planner.show', ['tripRequestId' => $tripRequestId]) }}">Talep özetine git</a>
            </x-slot:actions>
        </x-ui.empty-state>
    @else
        @if (count($typeCounts) > 1)
            <div class="traveler-filters" role="group" aria-label="Yolcu tipine göre süz" data-traveler-filters>
                <button type="button" class="chip" aria-pressed="true" data-traveler-filter="">Tümü ({{ count($slots) }})</button>
                @foreach ($typeCounts as $label => $count)
                    <button type="button" class="chip" aria-pressed="false" data-traveler-filter="{{ $label }}">{{ $label }} ({{ $count }})</button>
                @endforeach
            </div>
            <p class="visually-hidden" role="status" data-traveler-filter-status></p>
        @endif

        <ol class="request-list" data-traveler-list>
            @foreach ($slots as $slot)
                <li data-traveler-type="{{ $slot['typeLabel'] }}">
                    <article class="request-card" aria-labelledby="traveler-{{ $slot['number'] }}-title">
                        <div class="request-card-main">
                            <h2 id="traveler-{{ $slot['number'] }}-title" class="request-route">{{ $slot['label'] }}</h2>
                            <p class="request-facts"><span>{{ $slot['typeLabel'] }}</span></p>
                            <p class="traveler-badges">
                                <x-ui.badge variant="unverified">Doğrulama gerekli</x-ui.badge>
                                @if ($slot['minor'])
                                    <x-ui.badge variant="warning">Veli/vasi doğrulanmadı</x-ui.badge>
                                @endif
                                @if ($slot['traveler'])
                                    <x-ui.badge variant="success">Yolcu kaydı eklendi</x-ui.badge>
                                @endif
                            </p>
                        </div>
                        <div class="request-actions">
                            @if ($slot['traveler'])
                                <a class="btn btn-secondary" href="{{ route('traveler-screens.record', ['tripRequestId' => $tripRequestId, 'travelerId' => $slot['traveler']->id]) }}">Kayıt</a>
                            @endif
                            <a class="btn btn-primary" href="{{ route('traveler-screens.show', ['tripRequestId' => $tripRequestId, 'slot' => $slot['number']]) }}">Profil</a>
                            <a class="btn btn-secondary" href="{{ route('traveler-screens.edit', ['tripRequestId' => $tripRequestId, 'slot' => $slot['number']]) }}">Düzenle</a>
                            <a class="btn btn-secondary" href="{{ route('traveler-screens.documents', ['tripRequestId' => $tripRequestId, 'slot' => $slot['number']]) }}">Belgeler</a>
                        </div>
                    </article>
                </li>
            @endforeach
        </ol>
    @endif
@endsection
