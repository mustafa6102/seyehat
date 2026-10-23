{{-- Compact, non-technical trip summary for the flight search screen. Locations show their stored aviation code. --}}
<section class="card trip-brief" aria-labelledby="brief-title">
    <h2 id="brief-title" class="card-title">Seyahat özeti</h2>
    <dl class="brief-grid">
        <div><dt>Nereden</dt><dd><span class="summary-strong">{{ $origin['label'] }}</span><span class="summary-meta">{{ $origin['kind'] }}</span></dd></div>
        <div><dt>Nereye</dt><dd><span class="summary-strong">{{ $destination['label'] }}</span><span class="summary-meta">{{ $destination['kind'] }}</span></dd></div>
        @if ($journey['type'])
            <div><dt>Yolculuk</dt><dd><span class="summary-strong">{{ $journey['type'] }}</span></dd></div>
        @endif
        @foreach (['outbound' => 'Gidiş', 'return' => 'Dönüş'] as $direction => $directionLabel)
            @if ($journey[$direction])
                <div><dt>{{ $directionLabel }}</dt><dd><span class="summary-strong">{{ $journey[$direction]['text'] }}</span><span class="summary-meta">{{ $journey[$direction]['mode'] }}</span></dd></div>
            @endif
        @endforeach
        <div><dt>Yolcu sayısı</dt><dd><span class="summary-strong">{{ $travelerCount }}</span></dd></div>
    </dl>
</section>
