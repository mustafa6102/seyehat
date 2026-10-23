<?php

namespace App\Modules\FlightSearch\Application;

use App\Modules\FlightSearch\Contracts\FlightSelectionReader;
use App\Modules\FlightSearch\Domain\FlightOfferRepository;
use App\Modules\FlightSearch\Infrastructure\Persistence\DatabaseFlightSelectionRepository;
use DateTimeImmutable;
use Throwable;

/**
 * TripRequest flight selection (v3.13, TripRequestFlightSelectionStatus TRN-FSEL-01..04). Only server-side canonical
 * offers and the latest server-side verification are used; nothing about the offer is taken from the browser.
 */
final readonly class FlightSelectionService implements FlightSelectionReader
{
    public function __construct(
        private DatabaseFlightSelectionRepository $selections,
        private FlightOfferRepository $offers,
    ) {}

    public function find(string $tripRequestId, ?DateTimeImmutable $now = null): ?array
    {
        $selection = $this->selections->find($tripRequestId);
        $now ??= new DateTimeImmutable('now');
        if ($selection !== null && $selection['status'] !== 'EXPIRED' && new DateTimeImmutable($selection['expires_at']) <= $now) {
            $this->selections->markExpired($tripRequestId);

            return $this->selections->find($tripRequestId);
        }

        return $selection;
    }

    /**
     * TRN-FSEL-01: the offer belongs to this TripRequest's search results, its latest verification is CURRENT (or
     * CHANGED and explicitly accepted), it has not expired and $expectedVerifiedAt equals that verification.
     *
     * @return array<string, mixed>
     *
     * @throws FlightSelectionRejected
     */
    public function select(string $tripRequestId, string $offerId, string $expectedVerifiedAt, bool $acceptChangedOffer): array
    {
        $offer = $this->offers->find($offerId) ?? throw FlightSelectionRejected::notFound('Seçilecek uçuş teklifi bulunamadı.');
        if (! $this->selections->tripRequestHasOffer($tripRequestId, $offer->providerOfferId)) {
            throw FlightSelectionRejected::notSelectable();
        }
        $verification = $this->selections->latestVerification($offer->providerOfferId);
        $this->assertSelectable($offer->state(), $verification, $expectedVerifiedAt);
        if ($verification['state'] === 'CHANGED' && ! $acceptChangedOffer) {
            throw FlightSelectionRejected::changed();
        }

        $this->selections->store($tripRequestId, $offer, new DateTimeImmutable($verification['captured_at']), false);

        return $this->selections->find($tripRequestId);
    }

    /**
     * TRN-FSEL-04: RECONFIRMATION_REQUIRED -> CURRENT with the newly verified offer the user accepted explicitly.
     *
     * @return array<string, mixed>
     *
     * @throws FlightSelectionRejected
     */
    public function reconfirm(string $tripRequestId, string $expectedVerifiedAt): array
    {
        $selection = $this->find($tripRequestId) ?? throw FlightSelectionRejected::notFound('Bu seyahat talebi için seçili uçuş bulunamadı.');
        if ($selection['status'] === 'EXPIRED') {
            throw FlightSelectionRejected::expired();
        }
        if ($selection['status'] !== 'RECONFIRMATION_REQUIRED') {
            throw FlightSelectionRejected::notSelectable();
        }
        $offerId = (string) ($selection['offer']['provider_offer_id'] ?? '');
        $offer = $this->offers->find($offerId) ?? throw FlightSelectionRejected::notSelectable();
        if ($offer->state() === 'EXPIRED') {
            $this->selections->markExpired($tripRequestId);

            throw FlightSelectionRejected::expired();
        }
        $verification = $this->selections->latestVerification($offerId);
        $this->assertSelectable($offer->state(), $verification, $expectedVerifiedAt);

        $this->selections->store($tripRequestId, $offer, new DateTimeImmutable($verification['captured_at']), true);

        return $this->selections->find($tripRequestId);
    }

    /**
     * Records the OP-repriceOffer outcome as the offer's latest server-side verification and applies TRN-FSEL-02 on
     * CHANGED. UNVERIFIED, timeouts and EXPIRED results never change a selection's status.
     */
    public function recordReprice(RepriceResult $result): void
    {
        $offer = $result->current->offer;
        $state = $offer->state($result->current->capturedAt);
        $this->selections->rememberVerification($offer, $state, $result->current->capturedAt);
        if ($state === 'CHANGED') {
            $source = $offer->sources[0];
            $this->selections->requireReconfirmation($source->providerId, $source->providerOfferId);
        }
    }

    /** @param  array{state: string, captured_at: string}|null  $verification */
    private function assertSelectable(string $offerState, ?array $verification, string $expectedVerifiedAt): void
    {
        if ($offerState === 'EXPIRED' || ($verification['state'] ?? null) === 'EXPIRED') {
            throw FlightSelectionRejected::expired();
        }
        if ($verification === null || ! in_array($verification['state'], ['CURRENT', 'CHANGED'], true)) {
            throw FlightSelectionRejected::notSelectable();
        }
        if (! $this->sameInstant($expectedVerifiedAt, $verification['captured_at'])) {
            throw FlightSelectionRejected::changed();
        }
    }

    private function sameInstant(string $expected, string $actual): bool
    {
        try {
            return (new DateTimeImmutable($expected))->getTimestamp() === (new DateTimeImmutable($actual))->getTimestamp();
        } catch (Throwable) {
            return false;
        }
    }
}
