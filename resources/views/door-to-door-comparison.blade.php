@extends('layouts.app')

@section('title', 'Kapıdan kapıya karşılaştırma')
@section('description', 'Kapıdan kapıya rota karşılaştırması için gerekli doğrulanmış verilerin durumunu gösterir.')

@section('content')
    <nav class="breadcrumb" aria-label="Sayfa yolu">
        <a href="{{ route('trip-requests.mine') }}">Seyahatlerim</a>
        <span aria-hidden="true">/</span>
        <a href="{{ route('ground-connections.show', ['tripRequestId' => $tripRequestId]) }}">Kara bağlantıları</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page">Kapıdan kapıya karşılaştırma</span>
    </nav>

    <header class="page-header">
        <div>
            <p class="eyebrow">D2D-001 · Rota karşılaştırması</p>
            <h1 class="page-title">{{ $origin }} → {{ $destination }}</h1>
            <p class="page-lead">Rotalar ancak ilk ve son kilometre dahil bütün süre ve maliyet bileşenleri doğrulandığında karşılaştırılır.</p>
        </div>
        <a class="btn btn-secondary" href="{{ route('trip-plan.show', ['tripRequestId' => $tripRequestId]) }}">Seyahat Planım</a>
    </header>

    <section class="card" aria-labelledby="comparison-status-title">
        <h2 class="card-title" id="comparison-status-title">Karşılaştırma durumu</h2>
        <div class="alert alert-warning" role="status">
            <p><strong>{{ $comparisonStatus }}</strong> · Karşılaştırma yapılamıyor.</p>
        </div>
        <p>Eksik erişim süreleri, segment zamanları veya maliyetler sıfır kabul edilmedi. Bu nedenle en hızlı ya da en uygun rota seçilmedi.</p>
    </section>

    <section class="card" aria-labelledby="missing-evidence-title">
        <h2 class="card-title" id="missing-evidence-title">Karşılaştırma için gereken doğrulanmış veriler</h2>
        <ul>
            @foreach ($missingEvidence as $evidence)
                <li>{{ $evidence }}</li>
            @endforeach
        </ul>
        <p class="field-hint">D2D-001 kabul kriterleri tamamlanmış sayılmaz; süre, maliyet, sıralama veya uygulanabilirlik sonucu üretilmez.</p>
        <a class="btn btn-secondary" href="{{ route('ground-connections.show', ['tripRequestId' => $tripRequestId]) }}">Kara bağlantısı durumunu gör</a>
    </section>
@endsection
