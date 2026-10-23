{{-- Aç/kapa anahtarı: anında etkili ayarlar için. Yerel onay kutusu, role="switch" ile duyurulur. --}}
@props(['label', 'name', 'checked' => false])
<label class="toggle">
    <span>{{ $label }}</span>
    <input type="checkbox" role="switch" name="{{ $name }}" value="1" @checked($checked) {{ $attributes }}>
</label>
