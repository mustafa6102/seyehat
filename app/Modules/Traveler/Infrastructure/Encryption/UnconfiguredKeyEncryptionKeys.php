<?php

namespace App\Modules\Traveler\Infrastructure\Encryption;

use App\Modules\Traveler\Contracts\TravelerStorageClosed;
use App\Modules\Traveler\Domain\Encryption\KeyEncryptionKeys;
use App\Modules\Traveler\Domain\Encryption\WrappedDataKey;

/** The default until a KMS/HSM is approved: no key material exists here, and APP_KEY is deliberately not a stand-in. */
final class UnconfiguredKeyEncryptionKeys implements KeyEncryptionKeys
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function currentVersion(): string
    {
        throw new TravelerStorageClosed;
    }

    public function wrap(#[\SensitiveParameter] string $dataKey, string $context): WrappedDataKey
    {
        throw new TravelerStorageClosed;
    }

    public function unwrap(WrappedDataKey $wrapped, string $context): string
    {
        throw new TravelerStorageClosed;
    }
}
