{{-- Kart: yalnız gerçekten gruplanan içerik için. variant: flat | elevated · selected seçili durumu çizer. --}}
@props(['title' => null, 'variant' => null, 'selected' => false, 'as' => 'section'])
<{{ $as }} {{ $attributes->class(['card', "card-{$variant}" => $variant, 'is-selected' => $selected]) }}>
    @if ($title)<h2 class="card-title">{{ $title }}</h2>@endif
    {{ $slot }}
</{{ $as }}>
