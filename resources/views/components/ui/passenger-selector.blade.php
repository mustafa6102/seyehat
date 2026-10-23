{{-- Yolcu seçici: tür başına [−] değer [+]. Sayı girdileri gönderilir; düğmeleri trip-planner.js etkinleştirir. --}}
@props(['counts' => ['adults' => 1, 'children' => 0, 'infants' => 0], 'travelerCount' => 1, 'error' => null, 'invalid' => []])
@php
    $passengers = ['adults' => 'Yetişkin', 'children' => 'Çocuk', 'infants' => 'Bebek'];
    $total = array_sum(array_map(fn ($count) => is_numeric($count) ? (int) $count : 0, $counts));
@endphp
<fieldset class="form-section passengers" data-passengers @if ($error) aria-describedby="passenger-error" @endif>
    <legend>Yolcular</legend>
    <p class="passenger-total" aria-live="polite">Toplam <strong data-passenger-total>{{ $total }}</strong> yolcu</p>
    <div class="stepper-list">
        @foreach ($passengers as $field => $label)
            <div class="stepper" data-stepper>
                <label for="passenger-{{ $field }}">{{ $label }}</label>
                <div class="stepper-control">
                    <button type="button" class="stepper-button" data-step="-1" aria-label="{{ $label }} sayısını azalt" aria-controls="passenger-{{ $field }}" hidden>−</button>
                    <input id="passenger-{{ $field }}" type="number" name="{{ $field }}" min="0" step="1" value="{{ $counts[$field] }}" inputmode="numeric" @if (in_array($field, $invalid, true)) aria-invalid="true" @endif>
                    <button type="button" class="stepper-button" data-step="1" aria-label="{{ $label }} sayısını artır" aria-controls="passenger-{{ $field }}" hidden>+</button>
                </div>
            </div>
        @endforeach
    </div>
    @if ($error)
        <span id="passenger-error" class="field-error">{{ $error }}</span>
    @endif
    {{ $slot }}
    {{-- The server derives traveler_count from the composition; the script only mirrors the visible total. --}}
    <input type="hidden" name="traveler_count" value="{{ $travelerCount }}">
</fieldset>
