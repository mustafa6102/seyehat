<?php

namespace App\Modules\Traveler\Domain\Encryption;

/** A data key as only the key provider can open it, with the KEK version that wrapped it. */
final readonly class WrappedDataKey
{
    public function __construct(public string $ciphertext, public string $keyVersion) {}
}
