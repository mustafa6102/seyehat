{{-- Tarih alanı: yerel tarih seçici (klavye ve ekran okuyucu desteği tarayıcıdan gelir). --}}
@props(['label', 'name', 'id' => null, 'hint' => null, 'error' => null])
<x-ui.input :label="$label" :name="$name" :id="$id" :hint="$hint" :error="$error" type="date" {{ $attributes }} />
