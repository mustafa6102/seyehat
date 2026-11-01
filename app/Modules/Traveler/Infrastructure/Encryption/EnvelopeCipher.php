<?php

namespace App\Modules\Traveler\Infrastructure\Encryption;

use App\Modules\Traveler\Contracts\TravelerDataUnavailable;
use App\Modules\Traveler\Contracts\TravelerStorageClosed;

/** AES-256-GCM with a random 96-bit nonce. Stored format: nonce (12) + tag (16) + ciphertext. */
final class EnvelopeCipher
{
    public const KEY_BYTES = 32;

    private const CIPHER = 'aes-256-gcm';

    private const NONCE_BYTES = 12;

    private const TAG_BYTES = 16;

    public function encrypt(#[\SensitiveParameter] string $plaintext, #[\SensitiveParameter] string $key, string $aad): string
    {
        if (strlen($key) !== self::KEY_BYTES) {
            throw new TravelerStorageClosed;
        }
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, self::TAG_BYTES);
        if ($ciphertext === false) {
            throw new TravelerStorageClosed;
        }

        return $nonce.$tag.$ciphertext;
    }

    public function decrypt(string $envelope, #[\SensitiveParameter] string $key, string $aad): string
    {
        if (strlen($key) !== self::KEY_BYTES || strlen($envelope) <= self::NONCE_BYTES + self::TAG_BYTES) {
            throw new TravelerDataUnavailable;
        }
        $plaintext = openssl_decrypt(
            substr($envelope, self::NONCE_BYTES + self::TAG_BYTES),
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            substr($envelope, 0, self::NONCE_BYTES),
            substr($envelope, self::NONCE_BYTES, self::TAG_BYTES),
            $aad,
        );
        if ($plaintext === false) {
            throw new TravelerDataUnavailable;
        }

        return $plaintext;
    }
}
