{{-- Structural preview of a presentation-only screen: shapes only, never data. The archetype ($kind) comes from
     config/frontend_screens.php; $description says the layout in words for assistive technology and on screen. --}}
<figure class="wire wire-{{ $kind }}">
    <div class="wire-canvas" aria-hidden="true">
        @switch($kind)
            @case('search')
                <div class="wire-row"><span class="wire-pill wire-grow"></span><span class="wire-pill"></span><span class="wire-pill"></span></div>
                @foreach (range(1, 3) as $row)
                    <div class="wire-card wire-card-media">
                        <span class="wire-media"></span>
                        <span class="wire-lines"><span class="wire-line wire-w70"></span><span class="wire-line wire-w45"></span></span>
                        <span class="wire-price"></span>
                    </div>
                @endforeach
                @break
            @case('route')
                @foreach (range(1, 3) as $leg)
                    <div class="wire-leg"><span class="wire-dot"></span><span class="wire-lines"><span class="wire-line wire-w60"></span><span class="wire-line wire-w35"></span></span></div>
                    @if ($leg < 3)<div class="wire-connector"><span class="wire-flag"></span></div>@endif
                @endforeach
                @break
            @case('cost')
                <div class="wire-total"><span class="wire-line wire-w35"></span><span class="wire-figure"></span></div>
                @foreach (range(1, 4) as $row)
                    <div class="wire-row wire-split"><span class="wire-line wire-w45"></span><span class="wire-line wire-w20"></span></div>
                @endforeach
                @break
            @case('itinerary')
                <div class="wire-days">
                    @foreach (range(1, 3) as $day)
                        <div class="wire-day">
                            <span class="wire-line wire-w45 wire-strong"></span>
                            <span class="wire-slot"></span><span class="wire-slot"></span>@if ($day !== 2)<span class="wire-slot wire-slot-empty"></span>@endif
                        </div>
                    @endforeach
                </div>
                @break
            @case('ai')
                <div class="wire-split-panels">
                    <div class="wire-card"><span class="wire-line wire-w60 wire-strong"></span><span class="wire-line"></span><span class="wire-line wire-w80"></span><span class="wire-line wire-w70"></span></div>
                    <div class="wire-card wire-card-soft"><span class="wire-line wire-w45 wire-strong"></span><span class="wire-chip"></span><span class="wire-chip"></span><span class="wire-chip"></span></div>
                </div>
                @break
            @case('audit')
                @foreach (range(1, 4) as $row)
                    <div class="wire-row wire-check"><span class="wire-box"></span><span class="wire-line wire-w60"></span><span class="wire-pill wire-pill-sm"></span></div>
                @endforeach
                @break
            @case('live')
                <div class="wire-card wire-card-now"><span class="wire-line wire-w20"></span><span class="wire-line wire-w60 wire-strong"></span><span class="wire-line wire-w45"></span></div>
                <div class="wire-card"><span class="wire-line wire-w20"></span><span class="wire-line wire-w45"></span></div>
                <div class="wire-row"><span class="wire-tile"></span><span class="wire-tile"></span><span class="wire-tile"></span></div>
                @break
            @case('profile')
                <div class="wire-row"><span class="wire-step"></span><span class="wire-step"></span><span class="wire-step"></span></div>
                <div class="wire-field"></div><div class="wire-field"></div><div class="wire-row"><span class="wire-field wire-grow"></span><span class="wire-field wire-grow"></span></div>
                <div class="wire-button"></div>
                @break
            @default
                <div class="wire-row"><span class="wire-tile"></span><span class="wire-tile"></span></div>
                @foreach (range(1, 3) as $row)
                    <div class="wire-row wire-split"><span class="wire-line wire-w60"></span><span class="wire-toggle"></span></div>
                @endforeach
        @endswitch
    </div>
    <figcaption><span class="badge badge-outline">Düzen önizlemesi · veri içermez</span> {{ $description }}</figcaption>
</figure>
