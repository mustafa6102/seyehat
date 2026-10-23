{{-- Yükleniyor durumu: role="status" ile kibarca duyurulur; slot isteğe bağlı açıklamadır. --}}
@props(['title' => 'Yükleniyor…'])
<div role="status" {{ $attributes->class(['state', 'state-loading']) }}>
    <span class="state-icon"><span class="loading" aria-hidden="true"></span></span>
    <p class="state-title">{{ $title }}</p>
    @if ($slot->isNotEmpty())<p class="state-text">{{ $slot }}</p>@endif
</div>
