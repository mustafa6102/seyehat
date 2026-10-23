<?php

namespace App\Modules\ProviderAcl\Domain;

final class ProviderOperation
{
    public const SEARCH = 'search';

    public const PRICE = 'price';

    public const REPRICE = 'reprice';

    public const BOOK = 'book';

    public const TICKET = 'ticket';

    public const CHANGE = 'change';

    public const CANCEL = 'cancel';

    public const REFUND = 'refund';

    /** @var list<string> */
    public const ALL = [
        self::SEARCH,
        self::PRICE,
        self::REPRICE,
        self::BOOK,
        self::TICKET,
        self::CHANGE,
        self::CANCEL,
        self::REFUND,
    ];

    private function __construct() {}
}
