{{-- Tekli seçim satırı. Aynı name değerine sahip radyolar bir <fieldset> + <legend> içinde gruplanır. --}}
@props(['label', 'name', 'value', 'checked' => false, 'hint' => null])
<label class="check">
    <input type="radio" name="{{ $name }}" value="{{ $value }}" @checked($checked) {{ $attributes }}>
    <span>{{ $label }}@if ($hint)<span class="check-hint">{{ $hint }}</span>@endif</span>
</label>
