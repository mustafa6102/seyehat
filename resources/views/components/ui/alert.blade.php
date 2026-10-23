{{-- Uyarı: variant: info | success | warning | danger. Anlam renkle birlikte simge ve metinle de verilir. --}}
@props(['variant' => 'info', 'title' => null])
@php $icon = ['info' => 'info', 'success' => 'success', 'warning' => 'warning', 'danger' => 'error'][$variant]; @endphp
<div {{ $attributes->class(['alert', "alert-{$variant}"]) }}>
    <x-ui.icon :name="$icon" />
    <div>
        @if ($title)<p class="alert-title">{{ $title }}</p>@endif
        {{ $slot }}
    </div>
</div>
