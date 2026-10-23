{{-- Otel sonucu kartı: görsel, ad, konum, olgular, durum ve fiyat. status: available | limited | unavailable. --}}
@props(['name', 'area', 'rating' => null, 'facts' => [], 'price' => null, 'priceNote' => null, 'status' => null, 'statusText' => null, 'image' => null, 'selected' => false])
<article {{ $attributes->class(['hotel-card', 'is-selected' => $selected]) }}>
    <x-travel.media :src="$image" :alt="$name" />
    <div class="hotel-card-body">
        <div class="offer-heading">
            <h3 class="hotel-name">{{ $name }}</h3>
            @if ($selected)<x-ui.badge variant="success"><x-ui.icon name="check" size="sm" />Seçildi</x-ui.badge>@endif
        </div>
        <ul class="facts">
            <li class="fact"><x-ui.icon name="map-pin" size="sm" />{{ $area }}</li>
            @if ($rating)<li class="rating"><x-ui.icon name="star" size="sm" /><span class="visually-hidden">Puan: </span>{{ $rating }}</li>@endif
            @foreach ($facts as $icon => $text)
                <li class="fact"><x-ui.icon :name="$icon" size="sm" />{{ $text }}</li>
            @endforeach
        </ul>
        @if ($status)<span class="status status-{{ $status }}">{{ $statusText }}</span>@endif
        <div class="hotel-card-footer">
            <x-travel.price :amount="$price" :note="$priceNote" />
            {{ $slot }}
        </div>
    </div>
</article>
