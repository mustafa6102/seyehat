{{-- One owned plan item (v3.16 POI snapshot + v3.18 day assignment). Facts are the stored snapshot with their uncertainty;
     an assigned item has a day and in-day order only - never a time, duration or feasibility (RULE-DAY-001-05).
     Inputs: $item, $poiPlan, $tripRequestId, $dayOptions. --}}
<li>
    @if (($item['item_type'] ?? 'POI') === 'EVENT')
        <article class="offer-card">
            <header class="offer-card-header">
                <div>
                    <div class="offer-heading">
                        <h4 class="hotel-name">{{ $item['event']['name'] }}</h4>
                        <span class="badge badge-info">Sabit zamanlı etkinlik</span>
                    </div>
                    @if ($item['event']['starts_at'] && $item['event']['iana_timezone'])
                        <p class="offer-date">{{ \Illuminate\Support\Carbon::parse($item['event']['starts_at'])->timezone($item['event']['iana_timezone'])->locale('tr')->translatedFormat('j F Y, l H:i') }} · {{ $item['event']['iana_timezone'] }}</p>
                    @endif
                    @if ($item['event']['venue'])
                        <p class="summary-meta">{{ $item['event']['venue'] }}</p>
                    @endif
                </div>
            </header>
            <form method="post" action="{{ route('trip-plan.items.destroy', ['tripRequestId' => $tripRequestId, 'itemId' => $item['item_id']]) }}" data-plan-mutation>
                @csrf
                @method('DELETE')
                <input type="hidden" name="expected_version" value="{{ $poiPlan['version'] }}">
                <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                <button type="submit" class="btn btn-secondary">Plandan çıkar</button>
            </form>
        </article>
    @else
    <article class="offer-card">
        <header class="offer-card-header">
            <div>
                <div class="offer-heading">
                    <h4 class="hotel-name">{{ $item['poi']['name'] }}</h4>
                    @if ($item['date'] !== null && isset($item['count']))
                        <span class="badge badge-info">Esnek · {{ $item['position'] }}. sırada · saat atanmadı</span>
                        <span class="badge badge-warning">Uygulanabilirlik değerlendirilmedi</span>
                    @else
                        <span class="badge badge-info">Esnek · gün ve saat atanmadı</span>
                    @endif
                    @if ($item['duration'])
                        <span class="badge badge-unverified">{{ $item['duration']['minutes'] }} dakika · kullanıcı girişi · doğrulanmadı</span>
                    @endif
                    @if ($item['poi']['unverified'])
                        <span class="badge badge-unverified">Doğrulanmamış bilgi</span>
                    @endif
                </div>
                <p class="offer-date">{{ $item['poi']['address'] }}</p>
            </div>
        </header>
        <ul class="facts">
            @if ($item['date'] !== null && ! isset($item['count']))
                <li class="fact">Atandığı gün ({{ $item['date'] }}) seyahat günleri arasında değil.</li>
            @endif
            @foreach ($item['poi']['facts'] as $fact)
                <li class="fact">{{ $fact }}</li>
            @endforeach
            @if ($item['poi']['booking_url'])
                <li class="fact"><a href="{{ $item['poi']['booking_url'] }}" rel="noopener noreferrer" target="_blank">Rezervasyon sayfası</a></li>
            @endif
        </ul>
        <ul class="facts">
            @foreach ($item['poi']['hours'] as $line)
                <li class="fact">{{ $line }}</li>
            @endforeach
        </ul>
        <div class="plan-item-actions">
            {{-- HOW-001 v3.19: deterministic, source-backed Turkish guide for the CURRENT POI plan item. --}}
            <div class="plan-control-buttons">
                <button type="button" class="btn btn-secondary" data-how-guide-open aria-expanded="false" aria-controls="how-guide-{{ $item['item_id'] }}">Nasıl yapacağım?</button>
            </div>
            {{-- v3.18 OP-assignTripPlanItemDay over /web-api/v1 (OD-028 A); public/assets/plan-days.js sends it. --}}
            @if ($dayOptions !== [] || $item['date'] !== null)
                <div class="plan-control" data-day-assignment data-url="{{ $item['url'] }}" data-date="{{ $item['date'] ?? '' }}" data-position="{{ $item['position'] ?? '' }}">
                    <label for="day-select-{{ $item['item_id'] }}">Gün</label>
                    <select id="day-select-{{ $item['item_id'] }}" data-day-select>
                        <option value="" @selected($item['date'] === null)>Güne atanmamış</option>
                        @foreach ($dayOptions as $option)
                            <option value="{{ $option['date'] }}" @selected($item['date'] === $option['date'])>{{ $option['short'] }}</option>
                        @endforeach
                    </select>
                    <div class="plan-control-buttons">
                        <button type="button" class="btn btn-secondary" data-day-apply>Kaydet</button>
                        @if (isset($item['count']))
                            <button type="button" class="btn btn-ghost" data-day-move="up" @disabled($item['position'] === 1) aria-label="{{ $item['poi']['name'] }}: gün içinde yukarı taşı">Yukarı</button>
                            <button type="button" class="btn btn-ghost" data-day-move="down" @disabled($item['position'] === $item['count']) aria-label="{{ $item['poi']['name'] }}: gün içinde aşağı taşı">Aşağı</button>
                        @endif
                    </div>
                </div>
            @endif
            <div class="plan-control" data-plan-duration data-url="{{ $item['duration_url'] }}" data-current-minutes="{{ $item['duration']['minutes'] ?? '' }}">
                <label for="duration-{{ $item['item_id'] }}">Ziyaret süresi (dakika)</label>
                <input id="duration-{{ $item['item_id'] }}" type="number" inputmode="numeric" min="1" step="1" value="{{ $item['duration']['minutes'] ?? '' }}" data-duration-input aria-describedby="duration-note-{{ $item['item_id'] }}">
                <div class="plan-control-buttons">
                    <button type="button" class="btn btn-secondary" data-duration-save>{{ $item['duration'] ? 'Süreyi güncelle' : 'Süreyi kaydet' }}</button>
                </div>
                <span id="duration-note-{{ $item['item_id'] }}" class="summary-meta">Kullanıcı tarafından girilir; doğrulanmaz ve uygunluk hesabında kullanılmaz.</span>
            </div>
            <form method="post" action="{{ route('trip-plan.items.destroy', ['tripRequestId' => $tripRequestId, 'itemId' => $item['item_id']]) }}" data-plan-mutation>
                @csrf
                @method('DELETE')
                <input type="hidden" name="expected_version" value="{{ $poiPlan['version'] }}">
                <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                <button type="submit" class="btn btn-secondary">Plandan çıkar</button>
            </form>
        </div>
        <section id="how-guide-{{ $item['item_id'] }}" class="stack" data-how-guide data-guide-url="{{ $item['guide_url'] }}" data-guide-refresh-url="{{ $item['guide_refresh_url'] }}" data-guide-version="{{ $poiPlan['version'] }}" hidden aria-live="polite">
            <div class="cluster"><button type="button" class="btn btn-ghost" data-how-guide-close>Rehberi kapat</button></div>
            <p class="status-text" data-how-guide-status></p>
            <div class="stack" data-how-guide-content></div>
        </section>
    </article>
    @endif
</li>
