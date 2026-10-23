{{-- Uçuş sonucu kartı. flight.js'in ürettiği teklif kartıyla aynı sınıfları kullanır; iki yerde tek görünüm vardır. --}}
@props(['carrier', 'from', 'to', 'duration', 'stops' => 0, 'date' => null, 'price' => null, 'priceNote' => null, 'priceState' => 'known', 'facts' => [], 'highlight' => null, 'selected' => false])
<article {{ $attributes->class(['offer-card', 'is-selected' => $selected]) }}>
    <header class="offer-card-header">
        <div>
            <div class="offer-heading">
                <h3 class="offer-title">{{ $carrier }}</h3>
                @if ($highlight)<x-ui.badge variant="accent">{{ $highlight }}</x-ui.badge>@endif
                @if ($selected)<x-ui.badge variant="success"><x-ui.icon name="check" size="sm" />Seçildi</x-ui.badge>@endif
            </div>
            @if ($date)<p class="offer-date">{{ $date }}</p>@endif
        </div>
        <x-travel.price :amount="$price" :note="$priceNote" :state="$priceState" />
    </header>
    <x-travel.route :from="$from" :to="$to" :duration="$duration" :stops="$stops" />
    @if ($facts || $slot->isNotEmpty())
        <footer class="offer-card-footer">
            <ul class="facts">
                @foreach ($facts as $icon => $text)
                    <li class="fact"><x-ui.icon :name="$icon" size="sm" />{{ $text }}</li>
                @endforeach
            </ul>
            {{ $slot }}
        </footer>
    @endif
</article>
