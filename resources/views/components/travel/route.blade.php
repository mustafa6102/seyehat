{{-- Rota: kalkış → varış, süre ve aktarma. from/to = ['time' => '08:35', 'place' => 'IST · İstanbul']. stops = aktarma sayısı. --}}
@props(['from', 'to', 'duration' => null, 'stops' => 0])
@php $stopsText = $stops === 0 ? 'Aktarmasız' : "{$stops} aktarma"; @endphp
<div {{ $attributes->class(['route']) }}>
    <p class="route-point">
        <span class="route-time">{{ $from['time'] }}</span>
        <span class="route-place"><span class="visually-hidden">Kalkış: </span>{{ $from['place'] }}</span>
    </p>
    <p class="route-path">
        @if ($duration)<span class="route-duration"><span class="visually-hidden">Süre: </span>{{ $duration }}</span>@endif
        <span class="route-line" aria-hidden="true">
            @for ($stop = 1; $stop <= $stops; $stop++)
                <span class="route-stop" style="left: {{ round(100 * $stop / ($stops + 1)) }}%"></span>
            @endfor
        </span>
        <span class="route-stops @if ($stops === 0) is-direct @endif">{{ $stopsText }}</span>
    </p>
    <p class="route-point route-point-end">
        <span class="route-time">{{ $to['time'] }}</span>
        <span class="route-place"><span class="visually-hidden">Varış: </span>{{ $to['place'] }}</span>
    </p>
</div>
