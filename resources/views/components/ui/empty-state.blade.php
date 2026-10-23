{{-- Boş durum: ne olmadığını ve sıradaki adımı söyler. actions slotuna tek bir birincil eylem konur. --}}
@props(['title', 'icon' => 'compass', 'level' => 2])
<div {{ $attributes->class(['state']) }}>
    <span class="state-icon"><x-ui.icon :name="$icon" size="lg" /></span>
    <h{{ $level }} class="state-title">{{ $title }}</h{{ $level }}>
    <p class="state-text">{{ $slot }}</p>
    @isset($actions)<div class="state-actions">{{ $actions }}</div>@endisset
</div>
