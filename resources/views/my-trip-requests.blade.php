@extends('layouts.app')

@section('title', 'Taleplerim')

@push('scripts')
    <script src="{{ asset('assets/flight.js') }}" defer></script>
@endpush

@section('content')
    <header class="page-header">
        <div>
            <p class="eyebrow">Seyahat talepleri</p>
            <h1 class="page-title">Taleplerim</h1>
            <p class="page-lead">Bu tarayıcı oturumunda oluşturduğunuz talepler, en yenisi üstte. Oturum sona erdiğinde liste temizlenir; talepler bir hesaba bağlı değildir.</p>
        </div>
        @if ($cards !== [])
            <a class="btn btn-secondary" href="{{ route('trip-planner.index') }}">Yeni seyahat planı oluştur</a>
        @endif
    </header>

    @if ($cards === [])
        <section class="card empty-state" data-requests-empty>
            <h2 class="card-title">Henüz bir seyahat talebiniz yok.</h2>
            <p>Oluşturduğunuz talepler burada listelenir; buradan uçuş tekliflerine yeniden ulaşabilirsiniz.</p>
            <a class="btn btn-primary" href="{{ route('trip-planner.index') }}">Yeni seyahat planı oluştur</a>
        </section>
    @else
        <ol class="request-list" data-trip-requests data-poll-url="{{ route('web-api.flight-search-jobs.show', ['searchId' => '__SEARCH__']) }}">
            @foreach ($cards as $card)
                <li>
                    <article class="request-card" aria-labelledby="request-{{ $loop->index }}-title" data-request-card data-storage-key="flight-search:{{ $card['id'] }}">
                        <div class="request-card-main">
                            <h2 id="request-{{ $loop->index }}-title" class="request-route">{{ $card['origin'] }} <span aria-hidden="true">→</span><span class="visually-hidden">ile</span> {{ $card['destination'] }}</h2>
                            <p class="request-facts">
                                @if ($card['journey']['type'])
                                    <span>{{ $card['journey']['type'] }}</span>
                                @endif
                                @if ($card['journey']['outbound'])
                                    <span>Gidiş: {{ $card['journey']['outbound']['text'] }}</span>
                                @elseif ($card['journey']['legacy'])
                                    <span>Tarih: {{ $card['journey']['legacy']['text'] }}</span>
                                @endif
                                @if ($card['journey']['return'])
                                    <span>Dönüş: {{ $card['journey']['return']['text'] }}</span>
                                @endif
                                <span>{{ $card['travelerCount'] }} yolcu</span>
                            </p>
                            <p class="request-status" data-request-status role="status" hidden></p>
                        </div>
                        <div class="request-actions">
                            @if ($card['searchable'])
                                <a class="btn btn-primary" href="{{ route('flights.search', ['tripRequestId' => $card['id']]) }}" data-request-action>Uçuş ara</a>
                            @endif
                            <a class="btn btn-secondary" href="{{ route('trip-plan.show', ['tripRequestId' => $card['id']]) }}">Seyahat Planım</a>
                            <a class="btn btn-link" href="{{ route('trip-planner.show', ['tripRequestId' => $card['id']]) }}">Talep özeti</a>
                        </div>
                    </article>
                </li>
            @endforeach
        </ol>
    @endif
@endsection
