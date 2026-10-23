{{-- variant: primary | secondary | ghost | danger · size: sm | lg · href verilirse bağlantı olarak çizilir. --}}
@props(['variant' => 'primary', 'size' => null, 'href' => null, 'type' => 'button', 'icon' => null, 'loading' => false, 'block' => false])
@php
    $classes = ['btn', "btn-{$variant}", "btn-{$size}" => $size, 'btn-block' => $block];
@endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>@if ($icon)<x-ui.icon :name="$icon" />@endif{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }} @if ($loading) aria-busy="true" aria-disabled="true" @endif>@if ($icon && ! $loading)<x-ui.icon :name="$icon" />@endif{{ $slot }}</button>
@endif
