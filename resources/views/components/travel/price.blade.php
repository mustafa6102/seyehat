{{-- Fiyat: state: known | partial | unknown. Bilinmeyen veya eksik fiyat tutar gibi gösterilmez; durumu yazıyla söylenir. --}}
@props(['amount' => null, 'note' => null, 'state' => 'known', 'plain' => true])
<div {{ $attributes->class(['price', 'price-plain' => $plain && $state === 'known', "price-{$state}" => $state !== 'known']) }}>
    <p class="price-headline">{{ $state === 'unknown' ? 'Fiyat doğrulanamadı' : $amount }}</p>
    @if ($note)<p class="price-note">{{ $note }}</p>@endif
</div>
