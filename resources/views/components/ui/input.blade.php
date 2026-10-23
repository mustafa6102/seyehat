{{-- Etiketli metin alanı. hint ve error, aria-describedby ile girdiye bağlanır; error varsa aria-invalid eklenir. --}}
@props(['label', 'name', 'id' => null, 'type' => 'text', 'hint' => null, 'error' => null, 'optional' => false, 'icon' => null])
@php
    $id ??= $name;
    $describedBy = trim(($hint ? "{$id}-hint " : '').($error ? "{$id}-error" : ''));
@endphp
<div class="field">
    <label class="field-label" for="{{ $id }}">{{ $label }}@if ($optional) <span class="optional">İsteğe bağlı</span>@endif</label>
    <div class="input-group">
        @if ($icon)<x-ui.icon :name="$icon" />@endif
        <input id="{{ $id }}" type="{{ $type }}" name="{{ $name }}" @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif @if ($error) aria-invalid="true" @endif {{ $attributes }}>
    </div>
    @if ($hint)<small id="{{ $id }}-hint">{{ $hint }}</small>@endif
    @if ($error)<span id="{{ $id }}-error" class="field-error">{{ $error }}</span>@endif
</div>
