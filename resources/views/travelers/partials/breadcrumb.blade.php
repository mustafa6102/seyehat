<nav class="breadcrumb" aria-label="Sayfa yolu">
    <a href="{{ route('trip-requests.mine') }}">Seyahatlerim</a>
    <span aria-hidden="true">/</span>
    @if ($current === 'Yolcularım')
        <span aria-current="page">Yolcularım</span>
    @else
        <a href="{{ route('traveler-screens.index', ['tripRequestId' => $tripRequestId]) }}">Yolcularım</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page">{{ $current }}</span>
    @endif
</nav>
