{{-- One direction's SCH-TravelDate (v3.9): exact date or inclusive flexible range. $enabled=false renders it inert. --}}
@php
    $mode = old("{$prefix}_date_mode", 'exact');
    $groupError = $errors->first("{$prefix}_date") ?: $errors->first("{$prefix}_date_mode");
@endphp
<fieldset class="travel-date" data-travel-date="{{ $prefix }}" @unless ($enabled) disabled @endunless>
    <legend>{{ $legend }} tarihi</legend>
    <div class="segmented segmented-small" role="radiogroup" aria-label="{{ $legend }} için tarih tercihi">
        <label class="choice"><input type="radio" name="{{ $prefix }}_date_mode" value="exact" @checked($mode === 'exact')><span>Kesin tarih</span></label>
        <label class="choice"><input type="radio" name="{{ $prefix }}_date_mode" value="flexible" @checked($mode === 'flexible')><span>Esnek tarihler</span></label>
    </div>
    @if ($groupError)
        <span id="{{ $prefix }}-date-error" class="field-error">{{ $groupError }}</span>
    @endif
    <div class="date-fields" data-date-fields="exact" @if ($mode === 'flexible') hidden @endif>
        <label>
            <span class="visually-hidden">{{ $legend }} tarihi</span>
            <input type="date" name="{{ $prefix }}_exact_date" value="{{ old("{$prefix}_exact_date") }}" aria-describedby="@error("{$prefix}_exact_date") {{ $prefix }}-exact-date-error @enderror" @error("{$prefix}_exact_date") aria-invalid="true" @enderror>
            @error("{$prefix}_exact_date") <span id="{{ $prefix }}-exact-date-error" class="field-error">{{ $message }}</span> @enderror
        </label>
    </div>
    <div class="date-fields date-range" data-date-fields="flexible" @if ($mode !== 'flexible') hidden @endif>
        <label>
            <span>Başlangıç</span>
            <input type="date" name="{{ $prefix }}_start_date" value="{{ old("{$prefix}_start_date") }}" aria-describedby="{{ $prefix }}-range-help @error("{$prefix}_start_date") {{ $prefix }}-start-date-error @enderror" @error("{$prefix}_start_date") aria-invalid="true" @enderror>
            @error("{$prefix}_start_date") <span id="{{ $prefix }}-start-date-error" class="field-error">{{ $message }}</span> @enderror
        </label>
        <label>
            <span>Bitiş</span>
            <input type="date" name="{{ $prefix }}_end_date" value="{{ old("{$prefix}_end_date") }}" aria-describedby="{{ $prefix }}-range-help @error("{$prefix}_end_date") {{ $prefix }}-end-date-error @enderror" @error("{$prefix}_end_date") aria-invalid="true" @enderror>
            @error("{$prefix}_end_date") <span id="{{ $prefix }}-end-date-error" class="field-error">{{ $message }}</span> @enderror
        </label>
        <small id="{{ $prefix }}-range-help">Başlangıç ve bitiş günleri de uygun tarihlere dahildir.</small>
    </div>
</fieldset>
