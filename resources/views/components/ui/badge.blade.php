{{-- Durum etiketi (etkileşimsiz). variant: neutral | success | warning | error | info | accent | estimated | unverified | unavailable | outline.
     accent yalnız seyahat vurgusu içindir; güvenilirlik varyantları renge ek olarak işaret ve çerçeve biçimi taşır. --}}
@props(['variant' => 'neutral'])
<span {{ $attributes->class(['badge', "badge-{$variant}"]) }}>{{ $slot }}</span>
