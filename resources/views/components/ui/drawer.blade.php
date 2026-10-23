{{-- Çekmece: kenardan açılan <dialog>; dar ekranda filtreler gibi ikincil paneller için. --}}
@props(['id', 'title'])
<dialog class="drawer" id="{{ $id }}" aria-labelledby="{{ $id }}-title" {{ $attributes }}>
    <header class="dialog-header">
        <h2 class="dialog-title" id="{{ $id }}-title">{{ $title }}</h2>
        <x-ui.icon-button icon="close" label="Kapat" data-dialog-close />
    </header>
    <div class="dialog-body">{{ $slot }}</div>
    @isset($footer)<footer class="dialog-footer">{{ $footer }}</footer>@endisset
</dialog>
