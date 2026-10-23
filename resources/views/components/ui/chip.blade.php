{{-- Çip: pressed verilirse aç/kapa filtre düğmesidir (aria-pressed); verilmezse bilgi çipidir. --}}
@props(['pressed' => null, 'icon' => null])
@if ($pressed === null)
    <span {{ $attributes->class(['chip']) }}>@if ($icon)<x-ui.icon :name="$icon" size="sm" />@endif{{ $slot }}</span>
@else
    <button type="button" aria-pressed="{{ $pressed ? 'true' : 'false' }}" {{ $attributes->class(['chip']) }}>@if ($icon)<x-ui.icon :name="$icon" size="sm" />@endif{{ $slot }}</button>
@endif
