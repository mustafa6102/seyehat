{{-- Varış görseli çerçevesi: src yoksa sakin bir yer tutucu çizer; fotoğraf aynı orana oturur. --}}
@props(['src' => null, 'alt' => '', 'caption' => null, 'wide' => false])
<div {{ $attributes->class(['media', 'media-wide' => $wide]) }}>
    @if ($src)
        <img src="{{ $src }}" alt="{{ $alt }}" loading="lazy">
    @else
        <x-ui.icon name="image" size="lg" />
        <span class="visually-hidden">{{ $alt }} görseli henüz yok</span>
    @endif
    @if ($caption)<span class="media-caption">{{ $caption }}</span>@endif
</div>
