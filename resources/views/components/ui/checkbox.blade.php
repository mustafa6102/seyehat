{{-- Onay kutusu: yerel girdi etiketin içindedir; satırın tamamı tıklanabilir. --}}
@props(['label', 'name', 'value' => '1', 'checked' => false, 'hint' => null])
<label class="check">
    <input type="checkbox" name="{{ $name }}" value="{{ $value }}" @checked($checked) {{ $attributes }}>
    <span>{{ $label }}@if ($hint)<span class="check-hint">{{ $hint }}</span>@endif</span>
</label>
