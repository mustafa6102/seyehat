@extends('layouts.app')

@section('title', 'Plan değişiklik geçmişi')

@section('content')
    <header class="page-header">
        <div>
            <p class="eyebrow">Seyahat planı</p>
            <h1 class="page-title">Plan değişiklik geçmişi</h1>
            <p class="page-lead">Planın kaydedilmiş sürümleri ve her sürümde yapılan değişiklikler.</p>
        </div>
        <a class="btn btn-secondary" href="{{ route('trip-plan.show', ['tripRequestId' => $tripRequestId]) }}">Plana dön</a>
    </header>

    @if ($history === [])
        <section class="card empty-state">
            <h2 class="card-title">Henüz kaydedilmiş plan değişikliği yok</h2>
            <p>Plana bir öğe eklendiğinde ilk sürüm burada görünecek.</p>
        </section>
    @else
        <ol class="request-list">
            @foreach ($history as $revision)
                <li>
                    <article class="card" aria-labelledby="revision-{{ $revision['version'] }}">
                        <header class="offer-heading">
                            <h2 id="revision-{{ $revision['version'] }}" class="card-title">Sürüm {{ $revision['version'] }}</h2>
                            @if ($revision['current'])
                                <span class="badge badge-success">Güncel sürüm</span>
                            @else
                                <span class="badge badge-info">Önceki sürüm</span>
                            @endif
                        </header>
                        <p class="summary-meta">{{ $revision['created_at'] }} · {{ $revision['item_count'] }} plan öğesi · {{ $revision['reason'] }}</p>
                        @if ($revision['added'] !== [])
                            <p><strong>Eklendi:</strong> {{ implode(', ', $revision['added']) }}</p>
                        @endif
                        @if ($revision['removed'] !== [])
                            <p><strong>Çıkarıldı:</strong> {{ implode(', ', $revision['removed']) }}</p>
                        @endif
                        @if ($revision['moved'] !== [])
                            <p><strong>Günü veya sırası değişti:</strong> {{ implode(', ', $revision['moved']) }}</p>
                        @endif
                        @if ($revision['updated'] !== [])
                            <p><strong>Bilgisi güncellendi:</strong> {{ implode(', ', $revision['updated']) }}</p>
                        @endif
                    </article>
                </li>
            @endforeach
        </ol>
    @endif
@endsection
