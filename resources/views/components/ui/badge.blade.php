{{-- Durum etiketi (etkileşimsiz). variant: neutral | success | warning | error | info | accent. accent yalnız seyahat vurgusu içindir. --}}
@props(['variant' => 'neutral'])
<span {{ $attributes->class(['badge', "badge-{$variant}"]) }}>{{ $slot }}</span>
