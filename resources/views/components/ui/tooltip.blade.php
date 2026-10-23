{{-- İpucu: slot içindeki tetikleyici aria-describedby="{id}" taşımalıdır. Yalnız ek açıklama içindir; zorunlu bilgi ipucuna konmaz. --}}
@props(['id', 'text', 'align' => 'start'])
<span class="tooltip-host" data-tooltip-host>{{ $slot }}<span class="tooltip @if ($align === 'end') tooltip-end @endif" role="tooltip" id="{{ $id }}">{{ $text }}</span></span>
