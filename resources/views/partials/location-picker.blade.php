@php
    $locationError = $errors->first("{$prefix}_selection") ?: $errors->first("{$prefix}_value") ?: $errors->first("{$prefix}_kind") ?: $errors->first("{$prefix}_timezone");
    $flightMode ??= false;
@endphp
<fieldset class="place-group" data-location-picker="{{ $prefix }}" aria-describedby="{{ $prefix }}-help">
    <legend>{{ $legend }}</legend>
    {{-- Step instructions for assistive technology; the visible labels already name each field. --}}
    <p id="{{ $prefix }}-help" class="visually-hidden">{{ $flightMode ? $flightHelp : $help }}</p>

    <div class="place-row">
        <div class="combobox" data-combobox="country">
            <label for="{{ $prefix }}-country"><span class="visually-hidden">{{ $placeLabel }} </span>Ülke</label>
            <div class="combobox-control">
                <input
                    id="{{ $prefix }}-country"
                    type="text"
                    role="combobox"
                    aria-autocomplete="list"
                    aria-expanded="false"
                    aria-controls="{{ $prefix }}-country-listbox"
                    aria-describedby="{{ $prefix }}-help location-catalog-status"
                    autocomplete="off"
                    placeholder="Ülke seçin"
                    disabled
                >
                <ul id="{{ $prefix }}-country-listbox" class="combobox-listbox" role="listbox" aria-label="{{ $legend }} ülke önerileri" hidden></ul>
            </div>
        </div>

        <div class="combobox" data-combobox="location">
            <label for="{{ $prefix }}-location"><span class="visually-hidden">{{ $placeLabel }} </span>{{ $flightMode ? 'Havalimanı veya şehir' : 'Şehir, havaalanı veya istasyon' }}</label>
            <div class="combobox-control">
                <input
                    id="{{ $prefix }}-location"
                    type="text"
                    role="combobox"
                    aria-autocomplete="list"
                    aria-expanded="false"
                    aria-controls="{{ $prefix }}-location-listbox"
                    aria-describedby="{{ $prefix }}-help {{ $prefix }}-selection location-catalog-status @if ($locationError) {{ $prefix }}-location-error @endif"
                    @if ($locationError) aria-invalid="true" @endif
                    autocomplete="off"
                    placeholder="Önce ülke seçin"
                    disabled
                >
                <ul id="{{ $prefix }}-location-listbox" class="combobox-listbox" role="listbox" aria-label="{{ $legend }} konum önerileri" hidden></ul>
            </div>
        </div>
    </div>

    <p id="{{ $prefix }}-selection" class="selection-summary" aria-live="polite"></p>
    <p class="visually-hidden" aria-live="polite" data-combobox-status></p>
    @if ($locationError)
        <span id="{{ $prefix }}-location-error" class="field-error">{{ $locationError }}</span>
    @endif

    {{-- SCH-LocationRef fields: filled only from the selected canonical catalog entry, never typed by the user. --}}
    <input type="hidden" name="{{ $prefix }}_kind" value="{{ old("{$prefix}_kind") }}" data-location-field="kind">
    <input type="hidden" name="{{ $prefix }}_value" value="{{ old("{$prefix}_value") }}" data-location-field="value">
    <input type="hidden" name="{{ $prefix }}_timezone" value="{{ old("{$prefix}_timezone") }}" data-location-field="iana_timezone">
    {{-- Flight locations (OD-022 v3.8): opaque server-issued token; aviation identity is taken only from it on the server. --}}
    <input type="hidden" name="{{ $prefix }}_selection" value="{{ old("{$prefix}_selection") }}" data-location-field="selection">
</fieldset>
