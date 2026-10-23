<?php

namespace App\Modules\Accommodation\Application;

use RuntimeException;

/** A v3.14 accommodation selection/reconfirmation that is not applied; the current selection stays unchanged. */
final class AccommodationSelectionRejected extends RuntimeException
{
    public function __construct(public readonly string $machineCode, public readonly int $httpStatus, string $messageTr)
    {
        parent::__construct($messageTr);
    }

    public static function notFound(string $messageTr): self
    {
        return new self('RESOURCE_NOT_FOUND', 404, $messageTr);
    }

    /** RC-DECISION-OFFER-NOT-SELECTABLE */
    public static function notSelectable(): self
    {
        return new self('OFFER_NOT_SELECTABLE', 409, 'Bu konaklama teklifi şu anda seçilemez. Teklifi yeniden doğrulayın veya yeni bir arama yapın.');
    }

    /** RC-EVIDENCE-OFFER-CHANGED */
    public static function changed(): self
    {
        return new self('OFFER_CHANGED', 409, 'Konaklama teklifinin fiyatı veya koşulları değişti. Güncel bilgileri inceleyip yeniden onaylayın.');
    }

    /** RC-EVIDENCE-OFFER-EXPIRED */
    public static function expired(): self
    {
        return new self('OFFER_EXPIRED', 409, 'Konaklama teklifinin geçerlilik süresi doldu. Yeni bir konaklama araması yapın.');
    }
}
