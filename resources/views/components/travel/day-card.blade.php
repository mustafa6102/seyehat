{{-- Gün kartı ve zaman çizelgesi. items = [['time' => '09:00', 'title' => '…', 'text' => '…', 'travel' => bool]]. --}}
@props(['title', 'date' => null, 'items' => [], 'level' => 3])
<article {{ $attributes->class(['day-card']) }}>
    <header class="day-card-header">
        <h{{ $level }} class="day-card-title">{{ $title }}</h{{ $level }}>
        @if ($date)<span class="day-card-date">{{ $date }}</span>@endif
    </header>
    <ol class="timeline">
        @foreach ($items as $item)
            <li class="timeline-item @if ($item['travel'] ?? false) is-travel @endif">
                <span class="timeline-time">{{ $item['time'] }}</span>
                <div>
                    <p class="timeline-title">{{ $item['title'] }}</p>
                    @isset($item['text'])<p class="timeline-text">{{ $item['text'] }}</p>@endisset
                </div>
            </li>
        @endforeach
    </ol>
</article>
