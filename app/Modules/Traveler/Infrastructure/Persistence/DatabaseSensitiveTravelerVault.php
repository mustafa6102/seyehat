<?php

namespace App\Modules\Traveler\Infrastructure\Persistence;

use App\Modules\Traveler\Contracts\SensitiveTravelerProfile;
use App\Modules\Traveler\Contracts\TravelerDataUnavailable;
use App\Modules\Traveler\Contracts\TravelerStorageClosed;
use App\Modules\Traveler\Domain\Encryption\KeyEncryptionKeys;
use App\Modules\Traveler\Domain\Encryption\SensitiveTravelerVault;
use App\Modules\Traveler\Domain\Encryption\WrappedDataKey;
use App\Modules\Traveler\Infrastructure\Encryption\EnvelopeCipher;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use JsonException;
use Throwable;

/**
 * One data key per Traveler, wrapped by KeyEncryptionKeys. Each field is bound by AAD to its traveler, path and schema
 * version, so a ciphertext copied to another row fails authentication. A missing field row refuses the whole profile. A
 * key provider's own exception never leaves this class, because it could describe the key or the request.
 */
final readonly class DatabaseSensitiveTravelerVault implements SensitiveTravelerVault
{
    private const AAD_VERSION = 1;

    private const SCHEMA_VERSION = '3.3';

    public function __construct(
        private ConnectionInterface $database,
        private KeyEncryptionKeys $keys,
        private EnvelopeCipher $cipher,
    ) {}

    public function store(string $travelerId, SensitiveTravelerProfile $profile): void
    {
        if (! $this->keys->isConfigured()) {
            throw new TravelerStorageClosed;
        }

        try {
            $this->database->transaction(function () use ($travelerId, $profile): void {
                // Serializes writers: the first creates the data key and each merge reads what it then rewrites.
                if ($this->database->table('travelers')->where('id', $travelerId)->lockForUpdate()->first(['id']) === null) {
                    throw new TravelerStorageClosed;
                }

                $keyRow = $this->database->table('traveler_data_keys')->where('traveler_id', $travelerId)->first();
                $dataKey = $keyRow === null ? $this->createDataKey($travelerId) : $this->unwrap($keyRow, $travelerId);
                $stored = $keyRow === null ? array_fill_keys(SensitiveTravelerProfile::FIELDS, []) : $this->decryptFields($travelerId, $dataKey);

                foreach ($profile->fields() as $field => $entries) {
                    $added = $this->notYetStored($entries, $stored[$field]);
                    if ($keyRow === null || $added !== []) {
                        $this->writeField($travelerId, $field, [...$stored[$field], ...$added], $dataKey);
                    }
                }
            });
        } catch (TravelerDataUnavailable) {
            throw new TravelerStorageClosed;
        }
    }

    public function load(string $travelerId): ?SensitiveTravelerProfile
    {
        if (! $this->keys->isConfigured()) {
            throw new TravelerDataUnavailable;
        }
        $keyRow = $this->database->table('traveler_data_keys')->where('traveler_id', $travelerId)->first();
        if ($keyRow === null) {
            return null;
        }

        return SensitiveTravelerProfile::fromStored($this->decryptFields($travelerId, $this->unwrap($keyRow, $travelerId)));
    }

    public function rewrap(string $travelerId): bool
    {
        if (! $this->keys->isConfigured()) {
            throw new TravelerStorageClosed;
        }

        return $this->database->transaction(function () use ($travelerId): bool {
            $row = $this->database->table('traveler_data_keys')->where('traveler_id', $travelerId)->lockForUpdate()->first();
            if ($row === null || (string) $row->key_version === $this->currentVersion()) {
                return false;
            }
            $wrapped = $this->wrap($this->unwrap($row, $travelerId), $travelerId);
            $this->database->table('traveler_data_keys')->where('traveler_id', $travelerId)->update([
                'encrypted_dek' => $wrapped->ciphertext,
                'key_version' => $wrapped->keyVersion,
                'rotated_at' => $this->now(),
            ]);

            return true;
        });
    }

    public function rewrapStale(int $limit): array
    {
        if (! $this->keys->isConfigured()) {
            throw new TravelerStorageClosed;
        }

        $travelerIds = $this->database->table('traveler_data_keys')->where('key_version', '!=', $this->currentVersion())
            ->orderBy('traveler_id')->limit($limit)->pluck('traveler_id');

        $result = ['rewrapped' => 0, 'failed' => 0];
        foreach ($travelerIds as $travelerId) {
            try {
                $result['rewrapped'] += $this->rewrap((string) $travelerId) ? 1 : 0;
            } catch (TravelerStorageClosed|TravelerDataUnavailable) {
                $result['failed']++;
            }
        }

        return $result;
    }

    /**
     * A multiset difference: a visa or permit carries only its status, so two equal entries are two documents, not a repeat.
     *
     * @param  list<mixed>  $entries
     * @param  list<mixed>  $stored
     * @return list<mixed>
     */
    private function notYetStored(array $entries, array $stored): array
    {
        $added = [];
        foreach ($entries as $entry) {
            $match = array_search($entry, $stored, true);
            if ($match === false) {
                $added[] = $entry;
            } else {
                unset($stored[$match]);
            }
        }

        return $added;
    }

    /** @return array<string, list<mixed>> */
    private function decryptFields(string $travelerId, #[\SensitiveParameter] string $dataKey): array
    {
        $rows = $this->database->table('traveler_sensitive_fields')->where('traveler_id', $travelerId)->get()->keyBy('field_path');

        $fields = [];
        foreach (SensitiveTravelerProfile::FIELDS as $field) {
            $row = $rows->get($field);
            if ($row === null || (int) $row->aad_version !== self::AAD_VERSION) {
                throw new TravelerDataUnavailable;
            }
            $plaintext = $this->cipher->decrypt(
                (string) $row->ciphertext,
                $dataKey,
                $this->aad($travelerId, $field, (int) $row->aad_version, (string) $row->schema_version),
            );
            try {
                $fields[$field] = json_decode($plaintext, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new TravelerDataUnavailable;
            }
        }

        return $fields;
    }

    /** @param list<mixed> $entries */
    private function writeField(string $travelerId, string $field, array $entries, #[\SensitiveParameter] string $dataKey): void
    {
        $plaintext = json_encode($entries, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->database->table('traveler_sensitive_fields')->updateOrInsert(
            ['traveler_id' => $travelerId, 'field_path' => $field],
            [
                'ciphertext' => $this->cipher->encrypt($plaintext, $dataKey, $this->aad($travelerId, $field, self::AAD_VERSION, self::SCHEMA_VERSION)),
                'aad_version' => self::AAD_VERSION,
                'schema_version' => self::SCHEMA_VERSION,
                'updated_at' => $this->now(),
            ],
        );
    }

    private function createDataKey(string $travelerId): string
    {
        $dataKey = random_bytes(EnvelopeCipher::KEY_BYTES);
        $wrapped = $this->wrap($dataKey, $travelerId);
        $this->database->table('traveler_data_keys')->insert([
            'traveler_id' => $travelerId,
            'encrypted_dek' => $wrapped->ciphertext,
            'key_version' => $wrapped->keyVersion,
            'created_at' => $this->now(),
        ]);

        return $dataKey;
    }

    private function unwrap(object $keyRow, string $travelerId): string
    {
        try {
            return $this->keys->unwrap(new WrappedDataKey((string) $keyRow->encrypted_dek, (string) $keyRow->key_version), $travelerId);
        } catch (Throwable) {
            throw new TravelerDataUnavailable;
        }
    }

    private function wrap(#[\SensitiveParameter] string $dataKey, string $travelerId): WrappedDataKey
    {
        try {
            return $this->keys->wrap($dataKey, $travelerId);
        } catch (Throwable) {
            throw new TravelerStorageClosed;
        }
    }

    private function currentVersion(): string
    {
        try {
            return $this->keys->currentVersion();
        } catch (Throwable) {
            throw new TravelerStorageClosed;
        }
    }

    private function aad(string $travelerId, string $field, int $aadVersion, string $schemaVersion): string
    {
        return json_encode([$aadVersion, $travelerId, $field, $schemaVersion], JSON_THROW_ON_ERROR);
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
