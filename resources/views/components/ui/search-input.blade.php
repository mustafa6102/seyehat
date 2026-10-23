{{-- Arama alanı: büyüteç simgeli Input. Öneri listesi gerekiyorsa konum seçicideki combobox deseni kullanılır. --}}
@props(['label', 'name', 'id' => null, 'hint' => null, 'error' => null])
<x-ui.input :label="$label" :name="$name" :id="$id" :hint="$hint" :error="$error" type="search" icon="search" autocomplete="off" {{ $attributes }} />
