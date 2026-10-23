{{-- Sekmeler: tabs = [kimlik => etiket]; paneller slot içinde <x-ui.tab-panel id="kimlik"> ile verilir. Betik yoksa tüm paneller görünür kalır. --}}
@props(['label', 'tabs' => [], 'selected' => null, 'disabled' => []])
@php $selected ??= array_key_first($tabs); @endphp
<div class="tabs" data-tabs {{ $attributes }}>
    <div class="tabs-list" role="tablist" aria-label="{{ $label }}">
        @foreach ($tabs as $id => $text)
            <button type="button" class="tab" role="tab" id="tab-{{ $id }}" aria-controls="panel-{{ $id }}" aria-selected="{{ $id === $selected ? 'true' : 'false' }}" @disabled(in_array($id, $disabled, true))>{{ $text }}</button>
        @endforeach
    </div>
    {{ $slot }}
</div>
