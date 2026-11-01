@extends('layouts.app')

@section('title', 'Kara bağlantıları')
@section('description', 'Doğrulanmış kara ulaşımı verisinin durumunu güvenli biçimde gösterir.')

@section('content')
    <nav class="breadcrumb" aria-label="Sayfa yolu">
        <a href="{{ route('trip-requests.mine') }}">Taleplerim</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page">Kara bağlantıları</span>
    </nav>

    <header class="page-header">
        <div>
            <p class="eyebrow">Kara ulaşımı</p>
            <h1 class="page-title">{{ $origin }} → {{ $destination }}</h1>
            <p class="page-lead">Alternatif havaalanı, tren, otobüs ve son kilometre bağlantıları yalnız doğrulanmış kaynak bulunduğunda hesaplanır.</p>
        </div>
        <a class="btn btn-secondary" href="{{ route('trip-plan.show', ['tripRequestId' => $tripRequestId]) }}">Seyahat Planım</a>
    </header>

    <div class="layout-two">
        <section class="card" aria-labelledby="ground-status-title">
            <h2 class="card-title" id="ground-status-title">Kara bağlantısı durumu</h2>
            <div class="alert alert-warning" role="status">
                <p><strong>{{ $groundStatus }}</strong> · Doğrulanmış kara ulaşımı verisi bulunmuyor.</p>
            </div>
            <p>Tren, otobüs veya başka bir kara segmenti uydurulmadı. Süre, ücret, hareket saati, rezervasyon bağlantısı ve uygunluk sonucu gösterilmiyor.</p>
        </section>

        <section class="card" aria-labelledby="cost-status-title">
            <h2 class="card-title" id="cost-status-title">Maliyet kapsamı</h2>
            <dl class="summary-list compact">
                <div>
                    <dt>Toplam maliyet kapsamı</dt>
                    <dd><span class="badge badge-warning">{{ $costCompleteness }}</span></dd>
                </div>
            </dl>
            <p>Eksik kara ulaşımı bileşenleri sıfır kabul edilmez ve rota tam maliyetliymiş gibi sunulmaz.</p>
            <h3 class="card-title">Bilinmeyen bileşenler</h3>
            <ul>
                @foreach ($unknownComponents as $component)
                    <li>{{ $component }}</li>
                @endforeach
            </ul>
        </section>
    </div>

    <section class="card" aria-labelledby="blocked-title">
        <h2 class="card-title" id="blocked-title">Henüz kullanılamayan işlemler</h2>
        <p>Doğrulanmış sağlayıcı sözleşmesi bulunmadığından kara ulaşımı araması başlatılamaz ve tren ya da otobüs teklifi seçilemez.</p>
        <p class="field-hint">Sağlayıcı destekli fiyat, süre, kaynak, rezervasyon bağlantısı ve geçerlilik bilgileri henüz doğrulanmadı.</p>
        <a class="btn btn-secondary" href="{{ route('door-to-door-comparison.show', ['tripRequestId' => $tripRequestId]) }}">Kapıdan kapıya karşılaştırma durumunu gör</a>
    </section>
@endsection
