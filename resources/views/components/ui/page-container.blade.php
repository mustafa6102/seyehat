{{-- Sayfa kabı: width: default | narrow | wide. Yatay boşluk ve en fazla genişlik belirteçlerden gelir. --}}
@props(['width' => 'default'])
<div {{ $attributes->class(['container', "container-{$width}" => $width !== 'default']) }}>{{ $slot }}</div>
