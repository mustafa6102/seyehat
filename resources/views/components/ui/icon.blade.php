{{-- Tek simge seti: 24'lük ızgara, çizgi simge; renk ve kalınlık belirteçlerden gelir. Varsayılan olarak dekoratiftir. --}}
@props(['name', 'size' => null])
@php
    $paths = [
        'plane' => 'M21 3 3 10.5l6.5 2.5L12 20l3-6.5L21 3ZM9.5 13 21 3',
        'bed' => 'M3 18V7M3 13h18v5M21 13v-2a3 3 0 0 0-3-3h-7v5M7 10.5h.01',
        'calendar' => 'M4 6h16v14H4zM4 10h16M8 3v4M16 3v4',
        'users' => 'M9 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM2.5 20a6.5 6.5 0 0 1 13 0M16 4.2a3.5 3.5 0 0 1 0 6.6M18 14.5a6.5 6.5 0 0 1 3.5 5.5',
        'clock' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18ZM12 7v5l3 2',
        'search' => 'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14ZM20 20l-4-4',
        'close' => 'M6 6l12 12M18 6 6 18',
        'menu' => 'M4 7h16M4 12h16M4 17h16',
        'chevron-down' => 'm6 9 6 6 6-6',
        'check' => 'm5 12.5 4.5 4.5L19 7.5',
        'info' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18ZM12 11v5M12 8h.01',
        'warning' => 'M12 4 2.5 20h19L12 4ZM12 10v4.5M12 17.5h.01',
        'error' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18ZM12 7.5V13M12 16.5h.01',
        'success' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18ZM8 12.5l2.8 2.8L16 9.5',
        'map-pin' => 'M12 21s7-6.2 7-11.5a7 7 0 1 0-14 0C5 14.8 12 21 12 21ZM12 12a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Z',
        'filter' => 'M4 6h16M7 12h10M10 18h4',
        'star' => 'm12 3.5 2.6 5.4 5.9.8-4.3 4.1 1 5.9L12 16.9l-5.2 2.8 1-5.9-4.3-4.1 5.9-.8L12 3.5Z',
        'luggage' => 'M7 8h10v12H7zM10 8V4.5h4V8M10 12v4M14 12v4',
        'image' => 'M4 5h16v14H4zM4 16l4.5-4.5 3.5 3.5 3-3L20 17M9 9.5h.01',
        'utensils' => 'M7 3v8M5 3v5a2 2 0 0 0 4 0V3M7 11v10M17 21V3c-2 1-3 3.5-3 7v3h3',
        'ticket' => 'M3 7h18v3a2 2 0 0 0 0 4v3H3v-3a2 2 0 0 0 0-4V7ZM14 7v10',
        'plus' => 'M12 5v14M5 12h14',
        'compass' =>'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18ZM15.5 8.5l-2 5-5 2 2-5 5-2Z',
    ];
@endphp
<svg {{ $attributes->class(['icon', "icon-{$size}" => $size]) }} viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="{{ $paths[$name] }}"/></svg>
