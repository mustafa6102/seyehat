<?php

namespace Tests\Unit;

use App\Http\Support\TripPresentation;
use App\Modules\ProviderAcl\Contracts\LocationCatalog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TripPresentationTest extends TestCase
{
    #[Test]
    public function approved_and_unknown_internal_states_have_safe_turkish_labels(): void
    {
        $presentation = new TripPresentation($this->createStub(LocationCatalog::class));

        self::assertSame('Uçuş aramaya hazır', $presentation->status('SEARCHABLE'));
        self::assertSame('Taslak', $presentation->status('DRAFT'));
        self::assertSame('Durum bilgisi kullanılamıyor', $presentation->status('FUTURE_INTERNAL_STATE'));
    }

    #[Test]
    public function unknown_plan_reason_codes_are_not_exposed(): void
    {
        $presentation = new TripPresentation($this->createStub(LocationCatalog::class));

        self::assertSame('Kullanıcı değişikliği', $presentation->planReason('RC-DECISION-TRANSITION-APPLIED'));
        self::assertSame('Değişiklik gerekçesi ayrıntılı olarak gösterilemiyor', $presentation->planReason('RC-INTERNAL-FUTURE'));
    }
}
