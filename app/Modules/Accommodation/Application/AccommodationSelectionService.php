<?php

namespace App\Modules\Accommodation\Application;

use App\Modules\Accommodation\Contracts\AccommodationSelectionReader;
use App\Modules\Accommodation\Infrastructure\Persistence\DatabaseAccommodationSelectionRepository;
use DateTimeImmutable;
use Throwable;

/**
 * TripRequest accommodation selection (v3.14, TRN-ACCSEL-01..04). The latest server-side verification of an offer is the
 * offer stored by AccommodationOfferService (state CURRENT/CHANGED with source.verified_at = captured_at); nothing about
 * the offer is taken from the browser.
 */
final readonly class AccommodationSelectionService implements AccommodationSelectionReader
{
    public function __construct(
        private DatabaseAccommodationSelectionRepository $selections,
        private AccommodationOfferService $offers,
    ) {}

    public function find(string $tripRequestId, ?DateTimeImmutable $now = null): ?array
    {
        $selection = $this->selections->find($tripRequestId);
        if ($selection !== null && $selection['status'] !== 'EXPIRED' && new DateTimeImmutable($selection['expires_at']) <= ($now ?? new DateTimeImmutable('now'))) {
            $this->selections->markExpired($tripRequestId);

            return $this->selections->find($tripRequestId);
        }

        return $selection;
    }

    /**
     * TRN-ACCSEL-01.
     *
     * @return array<string, mixed>
     *
     * @throws AccommodationSelectionRejected
     */
    public function select(string $tripRequestId, string $offerId, string $expectedVerifiedAt, bool $acceptChangedOffer): array
    {
        $offer = $this->offers->find($offerId);
        if ($offer === null) {
            throw AccommodationSelectionRejected::notFound('Seçilecek konaklama teklifi bulunamadı veya süresi doldu.');
        }
        if (! $this->selections->tripRequestHasOffer($tripRequestId, $offerId)) {
            throw AccommodationSelectionRejected::notSelectable();
        }
        $this->assertVerified($offer, $expectedVerifiedAt);
        if ($offer['state'] === 'CHANGED' && ! $acceptChangedOffer) {
            throw AccommodationSelectionRejected::changed();
        }

        $this->selections->store($tripRequestId, $offer, false);

        return $this->selections->find($tripRequestId);
    }

    /**
     * TRN-ACCSEL-04: RECONFIRMATION_REQUIRED -> CURRENT with the latest verified offer the user accepted explicitly.
     *
     * @return array<string, mixed>
     *
     * @throws AccommodationSelectionRejected
     */
    public function reconfirm(string $tripRequestId, string $expectedVerifiedAt): array
    {
        $selection = $this->find($tripRequestId)
            ?? throw AccommodationSelectionRejected::notFound('Bu seyahat talebi için seçili konaklama bulunamadı.');
        if ($selection['status'] === 'EXPIRED') {
            throw AccommodationSelectionRejected::expired();
        }
        if ($selection['status'] !== 'RECONFIRMATION_REQUIRED') {
            throw AccommodationSelectionRejected::notSelectable();
        }
        $offer = $this->offers->find((string) ($selection['offer']['offer_id'] ?? ''));
        if ($offer === null) {
            // The stored offer is gone only after its provider expiry.
            $this->selections->markExpired($tripRequestId);

            throw AccommodationSelectionRejected::expired();
        }
        $this->assertVerified($offer, $expectedVerifiedAt);

        $this->selections->store($tripRequestId, $offer, true);

        return $this->selections->find($tripRequestId);
    }

    /**
     * TRN-ACCSEL-02 side effect of OP-revalidateAccommodationOffer: only CHANGED moves a CURRENT selection to
     * RECONFIRMATION_REQUIRED. UNVERIFIED, timeouts and EXPIRED never change a selection's status.
     *
     * @param  array<string, mixed>  $result  SCH-AccommodationRevalidationResult
     */
    public function recordRevalidation(array $result): void
    {
        $offer = $result['current']['offer'];
        if ($offer['state'] === 'CHANGED') {
            $this->selections->requireReconfirmation($offer['source']['provider_id'], $offer['source']['provider_offer_id']);
        }
    }

    /** @param  array<string, mixed>  $offer */
    private function assertVerified(array $offer, string $expectedVerifiedAt): void
    {
        if ($offer['state'] === 'EXPIRED' || new DateTimeImmutable((string) $offer['expires_at']) <= new DateTimeImmutable('now')) {
            throw AccommodationSelectionRejected::expired();
        }
        $verifiedAt = $offer['source']['verified_at'] ?? null;
        if (! in_array($offer['state'], ['CURRENT', 'CHANGED'], true) || ! is_string($verifiedAt)) {
            throw AccommodationSelectionRejected::notSelectable();
        }
        try {
            $same = (new DateTimeImmutable($expectedVerifiedAt))->getTimestamp() === (new DateTimeImmutable($verifiedAt))->getTimestamp();
        } catch (Throwable) {
            $same = false;
        }
        if (! $same) {
            throw AccommodationSelectionRejected::changed();
        }
    }
}
