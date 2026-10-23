{{-- Yalnız simge taşıyan düğme; erişilebilir adı label ile zorunludur. --}}
@props(['icon', 'label', 'outline' => false])
<button type="button" aria-label="{{ $label }}" {{ $attributes->class(['icon-btn', 'icon-btn-outline' => $outline]) }}><x-ui.icon :name="$icon" /></button>
