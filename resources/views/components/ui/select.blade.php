{{-- Yerel <select>: options = [değer => etiket]. Mobilde sistemin kendi seçicisini açar. --}}
@props(['label', 'name', 'options' => [], 'selected' => null, 'id' => null, 'hint' => null, 'error' => null, 'placeholder' => null])
@php
    $id ??= $name;
    $describedBy = trim(($hint ? "{$id}-hint " : '').($error ? "{$id}-error" : ''));
@endphp
<div class="field">
    <label class="field-label" for="{{ $id }}">{{ $label }}</label>
    <select id="{{ $id }}" name="{{ $name }}" @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif @if ($error) aria-invalid="true" @endif {{ $attributes }}>
        @if ($placeholder)<option value="" @selected($selected === null)>{{ $placeholder }}</option>@endif
        @foreach ($options as $value => $text)
            <option value="{{ $value }}" @selected((string) $selected === (string) $value)>{{ $text }}</option>
        @endforeach
    </select>
    @if ($hint)<small id="{{ $id }}-hint">{{ $hint }}</small>@endif
    @if ($error)<span id="{{ $id }}-error" class="field-error">{{ $error }}</span>@endif
</div>
