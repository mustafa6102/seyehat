@props(['route'])
@php
    $price = $route['price'] ?? null;
    $duration = max(0, (int) ($route['total_duration_seconds'] ?? 0));
    $connection = max(0, (int) ($route['connection_duration_seconds'] ?? 0));
    $hours = intdiv($duration, 3600);
    $minutes = intdiv($duration % 3600, 60);
    $priceText = $price ? $price['minor_units'].' '.$price['currency'].' (minor birim)' : null;
@endphp
<article {{ $attributes->class(['offer-card', 'self-transfer-card']) }}>
    <header class="offer-card-header">
        <div>
            <div class="offer-heading">
                <h3 class="offer-title">Kendin aktarmalı rota</h3>
                <x-ui.badge variant="warning">Doğrulama gerekli</x-ui.badge>
            </div>
            <p class="offer-date">Toplam süre: {{ $hours }} sa {{ $minutes }} dk</p>
        </div>
        <x-travel.price
            :amount="$priceText"
            :state="$price ? 'known' : 'unknown'"
            :note="$price ? 'Bilinen uçuş toplamı' : 'Toplam fiyat doğrulanamadı'"
        />
    </header>

    <ol class="timeline" aria-label="Bağımsız uçuşlar ve aktarma">
        @foreach (($route['segments'] ?? []) as $index => $segment)
            <li class="timeline-item">
                <strong>{{ $segment['origin_id'] }} → {{ $segment['destination_id'] }}</strong>
                <span>Bağımsız teklif {{ $index + 1 }} · {{ $segment['provider_offer_id'] }}</span>
            </li>
        @endforeach
    </ol>

    <footer class="offer-card-footer">
        <ul class="facts">
            <li class="fact">Aktarma: {{ intdiv($connection, 3600) }} sa {{ intdiv($connection % 3600, 60) }} dk</li>
            <li class="fact">Risk: {{ ($route['risk_level'] ?? 'UNKNOWN') === 'UNKNOWN' ? 'Doğrulama gerekli' : $route['risk_level'] }}</li>
            @foreach (($route['unknown_costs'] ?? []) as $cost)
                <li class="fact">Bilinmeyen ek maliyet: {{ $cost }}</li>
            @endforeach
        </ul>
        <p class="form-help">Biletler ayrıdır; kaçırılan bağlantı koruması, bagaj yeniden teslimi ve transit/giriş koşulları doğrulanmadan garanti verilmez.</p>
    </footer>
</article>
