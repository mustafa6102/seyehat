{{-- Bölüm başlığı: başlık, isteğe bağlı açıklama ve sağda eylemler (slot). --}}
@props(['title', 'description' => null, 'level' => 2, 'id' => null])
<div {{ $attributes->class(['section-header']) }}>
    <div>
        <h{{ $level }} class="section-title" @if ($id) id="{{ $id }}" @endif>{{ $title }}</h{{ $level }}>
        @if ($description)<p class="section-description">{{ $description }}</p>@endif
    </div>
    @if ($slot->isNotEmpty())<div class="cluster">{{ $slot }}</div>@endif
</div>
