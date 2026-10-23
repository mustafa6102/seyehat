{{-- Hata durumu: ne olduğunu ve nasıl düzeltileceğini söyler; role="alert" ile duyurulur. --}}
@props(['title', 'level' => 2])
<div role="alert" {{ $attributes->class(['state', 'state-error']) }}>
    <span class="state-icon"><x-ui.icon name="error" size="lg" /></span>
    <h{{ $level }} class="state-title">{{ $title }}</h{{ $level }}>
    <p class="state-text">{{ $slot }}</p>
    @isset($actions)<div class="state-actions">{{ $actions }}</div>@endisset
</div>
