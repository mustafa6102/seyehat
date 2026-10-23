{{-- Açılır menü: slot içine class="menu-item" data-menu-item taşıyan düğme/bağlantılar konur. Form değeri için Select kullanın. --}}
@props(['label', 'id', 'align' => 'start', 'icon' => null])
<div class="dropdown" data-dropdown {{ $attributes }}>
    <button type="button" class="btn btn-secondary" aria-haspopup="menu" aria-expanded="false" aria-controls="{{ $id }}" data-dropdown-toggle>
        @if ($icon)<x-ui.icon :name="$icon" />@endif{{ $label }}<x-ui.icon name="chevron-down" size="sm" />
    </button>
    <div class="menu @if ($align === 'end') menu-end @endif" id="{{ $id }}" role="menu" data-dropdown-menu hidden>{{ $slot }}</div>
</div>
