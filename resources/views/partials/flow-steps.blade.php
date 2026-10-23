@php
    $steps = ['request' => 'Seyahat talebi', 'flights' => 'Uçuş araması', 'offer' => 'Teklif'];
    $keys = array_keys($steps);
    $currentIndex = array_search($current, $keys, true);
@endphp
<nav class="flow-steps" aria-label="Planlama adımları">
    <ol>
        @foreach ($steps as $key => $label)
            @php $index = array_search($key, $keys, true); @endphp
            <li class="{{ $index < $currentIndex ? 'is-done' : ($index === $currentIndex ? 'is-current' : '') }}" @if ($index === $currentIndex) aria-current="step" @endif>
                <span class="flow-step-number" aria-hidden="true">{{ $index + 1 }}</span>
                <span>{{ $label }}</span>
            </li>
        @endforeach
    </ol>
</nav>
