{{-- Pencere: yerel <dialog>. data-dialog-open="{id}" taşıyan bir düğme açar; odak tuzağı ve Escape tarayıcıdan gelir. --}}
@props(['id', 'title'])
<dialog class="modal" id="{{ $id }}" aria-labelledby="{{ $id }}-title" {{ $attributes }}>
    <header class="dialog-header">
        <h2 class="dialog-title" id="{{ $id }}-title">{{ $title }}</h2>
        <x-ui.icon-button icon="close" label="Kapat" data-dialog-close />
    </header>
    <div class="dialog-body">{{ $slot }}</div>
    @isset($footer)<footer class="dialog-footer">{{ $footer }}</footer>@endisset
</dialog>
