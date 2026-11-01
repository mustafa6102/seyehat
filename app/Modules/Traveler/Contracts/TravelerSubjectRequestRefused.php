<?php

namespace App\Modules\Traveler\Contracts;

use RuntimeException;

final class TravelerSubjectRequestRefused extends RuntimeException
{
    public static function notOpen(): self
    {
        return new self('Açık bir başvuru bulunamadı.');
    }

    public static function unknownTraveler(): self
    {
        return new self('Bu yolcu kaydı veya başvuru türü geçersiz.');
    }

    public static function linkedSensitiveRecords(): self
    {
        return new self('Yolcuya bağlı hassas kayıt bulunduğu için başvuru işlenemedi.');
    }

    public static function auditUnavailable(): self
    {
        return new self('Denetim kaydı yazılamadığı için başvuru işlenemedi.');
    }
}
