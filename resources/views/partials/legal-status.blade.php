@if ($notice['is_draft'])
    <x-ui.alert variant="warning" title="{{ $notice['pending_fields'] > 0 ? 'Taslak: yürürlükte değil' : 'Onay bekliyor: yürürlükte değil' }}">
        <p>{{ $notice['status_note'] }}</p>
        <p>Metin sürümü {{ $notice['version'] }}@if ($notice['pending_fields'] > 0) · Hukuki incelemede doldurulacak {{ $notice['pending_fields'] }} alan vurgulanmıştır.@endif</p>
    </x-ui.alert>
@endif
