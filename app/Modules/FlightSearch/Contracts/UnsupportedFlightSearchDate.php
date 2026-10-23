<?php

namespace App\Modules\FlightSearch\Contracts;

use InvalidArgumentException;

/** The TripRequest journey/dates are not representable as SCH-FlightSearchCriteria v3.9 (OD-022 DATE). */
final class UnsupportedFlightSearchDate extends InvalidArgumentException
{
    public function __construct(string $messageTr = 'Seyahat talebinin yolculuk tipi veya tarih bilgisi uçuş araması için kullanılabilir biçimde değil (geçerli bir gidiş tarihi, gidiş-dönüşte de geçerli bir dönüş tarihi gerekli).')
    {
        parent::__construct($messageTr);
    }

    /** Pre-v3.9 record with a date_window of unknown direction (legacy, unresolved). */
    public static function legacy(): self
    {
        return new self('Bu seyahat talebi, tek yön/gidiş-dönüş seçimi eklenmeden önceki tarih biçimiyle kaydedildi; tarihin gidiş tarihi mi yoksa seyahat süresi mi olduğu bilinmediğinden uçuş araması yapılamaz. Yolculuk tipi ve tarihleriyle yeni bir seyahat talebi oluşturun.');
    }
}
