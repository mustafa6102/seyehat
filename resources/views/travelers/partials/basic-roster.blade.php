<section class="card" aria-labelledby="basic-roster-title">
    <h2 class="card-title" id="basic-roster-title">Eklenen yetişkin yolcular</h2>

    @if (session('traveler_status'))
        <p class="alert alert-success" role="status">{{ session('traveler_status') }}</p>
    @endif
    @if (session('traveler_error'))
        <p class="alert alert-warning" role="alert">{{ session('traveler_error') }}</p>
    @endif

    {{-- An unverified legacy row may occupy a seat, so no free capacity is implied while one exists. --}}
    @if ($roster->adultCapacity !== null && ! $roster->hasUnverifiedRows)
        <p>{{ count($roster->travelers) }} / {{ $roster->adultCapacity }} yetişkin yolcu eklendi.</p>
    @else
        <p>{{ count($roster->travelers) }} yetişkin yolcu eklendi.</p>
    @endif

    @include('travelers.partials.notice-link')

    @if ($roster->travelers !== [])
        <ol class="request-list">
            @foreach ($roster->travelers as $traveler)
                <li>
                    <article class="request-card">
                        <div class="request-card-main">
                            <h3 class="request-route">Yetişkin yolcu {{ $loop->iteration }}</h3>
                        </div>
                        <div class="request-actions">
                            <a class="btn btn-secondary" href="{{ route('traveler-screens.record', ['tripRequestId' => $tripRequestId, 'travelerId' => $traveler->id]) }}">Detay</a>
                        </div>
                    </article>
                </li>
            @endforeach
        </ol>
    @endif

    @if (! $roster->tripExists)
        <p>Yolcu eklemek için önce plana en az bir öğe ekleyin. <a href="{{ route('trip-plan.show', ['tripRequestId' => $tripRequestId]) }}">Planı aç</a></p>
    @elseif ($roster->hasUnverifiedRows)
        <p>Bu seyahatte doğrulanamayan eski bir yolcu kaydı bulunduğu için yeni yolcu eklenemiyor.</p>
    @elseif ($roster->adultCapacity === null)
        <p>Bu talepte yetişkin sayısı belirtilmediği için yolcu eklenemez.</p>
    @elseif ($roster->canAddAdult())
        <form method="post" action="{{ route('traveler-screens.store', ['tripRequestId' => $tripRequestId]) }}">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
            <button type="submit" class="btn btn-primary">Yetişkin yolcu ekle</button>
        </form>
    @else
        <p>Bu seyahatin yetişkin yolcu kapasitesi doldu.</p>
    @endif
</section>
