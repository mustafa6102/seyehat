<?php

namespace App\Modules\FlightSearch\Infrastructure\Adapters;

use App\Modules\FlightSearch\Application\OfferPriceCalculator;
use App\Modules\FlightSearch\Contracts\FlightProviderAdapter;
use App\Modules\FlightSearch\Contracts\FlightSearchCriteria;
use App\Modules\FlightSearch\Domain\BaggageAllowance;
use App\Modules\FlightSearch\Domain\FlightOffer;
use App\Modules\FlightSearch\Domain\FlightSegment;
use App\Modules\FlightSearch\Domain\Money;
use App\Modules\FlightSearch\Domain\ProviderSource;
use App\Modules\ProviderAcl\Contracts\ProviderCapabilityMatrix;
use DateTimeImmutable;

abstract class AbstractFlightProviderAdapter implements FlightProviderAdapter
{
    private ?int $callTimeoutMs = null;

    public function __construct(
        private readonly ProviderCapabilityMatrix $capabilityMatrix,
        private readonly string $providerId,
    ) {}

    final public function providerId(): string
    {
        return $this->providerId;
    }

    final public function search(FlightSearchCriteria $criteria, ?int $timeoutMs = null): array
    {
        if (! $this->capabilityMatrix->productionCapability($this->providerId, 'search')->supported) {
            return [];
        }

        $this->callTimeoutMs = $timeoutMs;
        $offers = [];
        foreach ($this->fetchRawOffers($criteria) as $rawOffer) {
            $offers[] = $this->normalizeRawOffer($rawOffer);
        }

        return $offers;
    }

    /**
     * Concrete adapters must throw ProviderFailure::timeout()/rateLimited()/unavailable() for
     * timeout, HTTP 429 and HTTP 5xx/transport errors; raw vendor errors must not escape.
     *
     * @return iterable<mixed> Provider SDK/raw values remain inside the concrete adapter.
     */
    abstract protected function fetchRawOffers(FlightSearchCriteria $criteria): iterable;

    /** Provider-scoped timeout (PLT-REL-001) to apply to the transport of the current call. */
    final protected function callTimeoutMs(): ?int
    {
        return $this->callTimeoutMs;
    }

    abstract protected function normalizeRawOffer(mixed $raw): FlightOffer;

    /**
     * @param  list<FlightSegment>  $segments
     */
    final protected function canonicalOffer(
        string $providerOfferId,
        ?string $providerRole,
        ?string $distributionChannel,
        DateTimeImmutable $fetchedAt,
        ?DateTimeImmutable $verifiedAt,
        ?DateTimeImmutable $expiresAt,
        Money $price,
        string $priceCertainty,
        string $priceCompleteness,
        BaggageAllowance $baggage,
        array $segments,
    ): FlightOffer {
        $search = $this->capabilityMatrix->productionCapability($this->providerId, 'search');
        $book = $this->capabilityMatrix->productionCapability($this->providerId, 'book');
        $bookable = $search->supported && $book->supported;
        $sourcedBaggage = $baggage->withSource(
            $this->providerId.':'.$providerOfferId,
            $fetchedAt,
            $verifiedAt,
            $expiresAt,
        );
        $calculatedPrice = (new OfferPriceCalculator)->calculate(
            $price,
            $priceCompleteness,
            $sourcedBaggage,
            $priceCertainty,
            [$this->providerId.':'.$providerOfferId.':price'],
            $fetchedAt,
        );

        return new FlightOffer(
            providerOfferId: $providerOfferId,
            price: $calculatedPrice->value ?? $calculatedPrice->knownLowerBound,
            priceCertainty: $priceCertainty,
            priceCompleteness: $calculatedPrice->completeness,
            baggage: $sourcedBaggage,
            expiresAt: $expiresAt,
            sources: [new ProviderSource(
                providerId: $this->providerId,
                providerOfferId: $providerOfferId,
                providerRole: $providerRole ?? 'UNKNOWN',
                distributionChannel: $distributionChannel ?? 'UNKNOWN',
                searchable: $search->supported,
                bookable: $bookable,
                searchCapabilityStatus: $search->status,
                bookCapabilityStatus: $book->status,
                fetchedAt: $fetchedAt,
                verifiedAt: $verifiedAt,
                expiresAt: $expiresAt,
            )],
            segments: $segments,
            bookable: $bookable,
            calculatedPrice: $calculatedPrice,
        );
    }
}
