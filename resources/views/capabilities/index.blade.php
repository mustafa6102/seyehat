@extends('layouts.app')

@section('title', 'Teknik özellik dizini')
@section('description', 'Seyahat planlayıcısının çalışan ve planlanan tüm kullanıcı ekranları.')
@section('main-class', 'container-wide page')

@php
    $allStories = collect($stories);
    $availableCount = $allStories->filter(fn (array $story) => $story['route'] !== null)->count();
@endphp

@section('content')
    <header class="hero capability-hero">
        <div class="hero-body">
            <p class="hero-eyebrow">Teknik referans</p>
            <h1 class="hero-title">Özellik ve gereksinim dizini</h1>
            <p class="hero-lead">Tüm kullanıcı, altyapı ve denetim yeteneklerini EPIC, aşama ve gereksinim izleriyle inceleyin. Günlük kullanım için yolculuk rehberine dönün.</p>
            <div class="hero-actions">
                <a class="btn btn-accent btn-lg" href="{{ route('journey.index') }}">Yolculuk rehberine dön</a>
            </div>
        </div>
        <dl class="hero-stats">
            <div><dt>Ekran</dt><dd>{{ $allStories->count() }}</dd></div>
            <div><dt>Kullanılabilir akış</dt><dd>{{ $availableCount }}</dd></div>
            <div><dt>Sunum ekranı</dt><dd>{{ $allStories->count() - $availableCount }}</dd></div>
        </dl>
    </header>

    <div class="status-legend" role="note" aria-label="Durum açıklaması">
        <span class="badge badge-success">Kullanılabilir akış</span>
        <span>Gerçek servisler ve onaylı sözleşmelerle çalışır.</span>
        <span class="badge badge-unavailable">Sunum ekranı</span>
        <span>Kapsamı gösterir; kayıt, fiyat veya uygunluk sonucu üretmez.</span>
    </div>

    <div class="catalog-layout">
        <nav class="catalog-nav" aria-label="Özellik grupları">
            <p class="catalog-nav-title">Gruplar</p>
            <ul>
                @foreach ($epics as $epic)
                    @php($count = $allStories->filter(fn (array $story) => in_array($epic['id'], $story['epics'], true))->count())
                    <li>
                        <a href="#{{ strtolower($epic['id']) }}">
                            <x-ui.icon :name="$archetypes[$epic['archetype']]['icon']" size="sm" />
                            <span>{{ $epic['title'] }}</span>
                            <span class="catalog-nav-count" aria-label="{{ $count }} ekran">{{ $count }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>

        <div class="catalog-content">
            @foreach ($epics as $epic)
                @php($epicStories = $allStories->filter(fn (array $story) => in_array($epic['id'], $story['epics'], true)))
                @php($archetype = $archetypes[$epic['archetype']])
                <section class="capability-section" id="{{ strtolower($epic['id']) }}" aria-labelledby="{{ strtolower($epic['id']) }}-title">
                    <div class="capability-section-header">
                        <span class="icon-tile icon-tile-{{ $epic['archetype'] }}"><x-ui.icon :name="$archetype['icon']" /></span>
                        <div>
                            <p class="eyebrow">{{ $archetype['label'] }} · {{ $epic['milestone'] }}</p>
                            <h2 class="section-title" id="{{ strtolower($epic['id']) }}-title">{{ $epic['title'] }}</h2>
                        </div>
                        <span class="badge badge-neutral">{{ $epicStories->count() }} ekran</span>
                    </div>

                    @if ($epicStories->isEmpty())
                        <div class="state state-compact">
                            <h3 class="state-title">Henüz tanımlı kullanıcı ekranı yok</h3>
                            <p class="state-text">Bu grup için aktif analizde bağımsız bir kullanıcı hikâyesi bulunmuyor.</p>
                        </div>
                    @else
                        <ul class="capability-grid">
                            @foreach ($epicStories as $story)
                                <li>
                                    <article @class(['capability-card', 'is-available' => $story['route']])>
                                        <div class="capability-meta">
                                            <span class="badge {{ $story['route'] ? 'badge-success' : 'badge-unavailable' }}">{{ $story['route'] ? 'Kullanılabilir akış' : 'Sunum ekranı' }}</span>
                                            <span class="badge badge-outline">{{ $story['phase'] }}</span>
                                        </div>
                                        <h3 class="card-title"><a class="stretched-link" href="{{ route('capabilities.show', ['storyId' => $story['id']]) }}">{{ $story['title'] }}</a></h3>
                                        <p class="capability-card-text">{{ $story['value'] }}</p>
                                        <p class="capability-card-footer" aria-hidden="true">
                                            <span>{{ count($story['tasks']) }} görev · {{ count($story['rules']) }} kural</span>
                                            <span class="capability-card-cta">Ekranı aç <x-ui.icon name="arrow-right" size="sm" /></span>
                                        </p>
                                    </article>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endforeach
        </div>
    </div>
@endsection
