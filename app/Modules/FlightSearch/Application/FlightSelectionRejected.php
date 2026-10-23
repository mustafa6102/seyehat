<?php

namespace App\Modules\FlightSearch\Application;

use RuntimeException;

/** A v3.13 selection/reconfirmation that is not applied; the current selection stays unchanged. */
final class FlightSelectionRejected extends RuntimeException
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
        return new self('OFFER_NOT_SELECTABLE', 409, 'Bu teklif şu anda seçilemez. Teklifi yeniden doğrulayın veya yeni bir arama yapın.');
    }

    /** RC-EVIDENCE-OFFER-CHANGED */
    public static function changed(): self
    {
        return new self('OFFER_CHANGED', 409, 'Teklifin fiyatı veya kritik koşulları değişti. Güncel bilgileri inceleyip yeniden onaylayın.');
    }

    /** RC-EVIDENCE-OFFER-EXPIRED */
    public static function expired(): self
    {
        return new self('OFFER_EXPIRED', 409, 'Teklifin geçerlilik süresi doldu. Yeni bir uçuş araması yapın.');
    }
}
