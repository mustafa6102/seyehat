{{-- İskelet: içeriğin biçimini taşıyan yer tutucu. Ekran okuyucudan gizlidir; durumu LoadingState veya aria-busy duyurur. --}}
@props(['variant' => 'text', 'lines' => 1])
@for ($line = 0; $line < $lines; $line++)
    <span {{ $attributes->class(['skeleton', "skeleton-{$variant}"]) }} aria-hidden="true"></span>
@endfor
