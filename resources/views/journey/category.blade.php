@extends('layouts.app')

@php
    $categoryId = request()->segment(2);
    $category = $categories[$categoryId] ?? null;
    abort_unless($category, 404);
    $categoryItems = collect($categoryStories[$categoryId])->map(fn (string $id) => $stories[$id]);
@endphp

@section('title', $category['title'])
@section('description', $category['description'])
@section('main-class', 'container-wide page')

@section('content')
    <nav class="breadcrumb" aria-label="Sayfa yolu">
        <a href="{{ route('journey.index') }}">Yolculuk rehberi</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page">{{ $category['title'] }}</span>
    </nav>

    <header class="category-header">
        <span class="icon-tile"><x-ui.icon :name="$category['icon']" size="lg" /></span>
        <div>
            <p class="eyebrow">Seyahat adımı</p>
            <h1>{{ $category['title'] }}</h1>
            <p>{{ $category['description'] }}</p>
        </div>
    </header>

    <ul class="journey-feature-list">
        @foreach ($categoryItems as $story)
            <li>
                <article class="journey-feature-card">
                    <div class="capability-meta">
                        <span class="badge {{ $story['route'] ? 'badge-success' : 'badge-unavailable' }}">{{ $story['route'] ? 'Kullanılabilir' : 'Planlanıyor' }}</span>
                        @if ($story['classification'] === 'contextual')
                            <span class="badge badge-outline">Plan içinde açılır</span>
                        @elseif ($story['classification'] === 'trip_context')
                            <span class="badge badge-outline">Seyahate özel</span>
                        @endif
                    </div>
                    <h2><a class="stretched-link" href="{{ route('capabilities.show', ['storyId' => $story['id']]) }}">{{ $story['title'] }}</a></h2>
                    <p>{{ $story['value'] }}</p>
                    <span class="journey-feature-cta" aria-hidden="true">Ayrıntıları gör <x-ui.icon name="arrow-right" size="sm" /></span>
                </article>
            </li>
        @endforeach
    </ul>

    <nav class="category-pager" aria-label="Diğer seyahat adımları">
        <a class="btn btn-secondary" href="{{ route('journey.index') }}">Tüm kategoriler</a>
        <a class="btn btn-primary" href="{{ route('trip-planner.index') }}#yeni-plan">Yeni plan oluştur</a>
    </nav>
@endsection
