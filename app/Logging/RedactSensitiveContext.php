<?php

namespace App\Logging;

use Closure;
use Monolog\LogRecord;
use Throwable;

final class RedactSensitiveContext
{
    private const REDACTED = '[REDACTED]';

    /** Normalized (lowercase, "-" as "_") context keys whose values are never written. */
    private const SENSITIVE_KEYS = [
        'access_token' => true,
        'address' => true,
        'api_key' => true,
        'api_token' => true,
        'authorization' => true,
        'birth_date' => true,
        'bot_user_oauth_token' => true,
        'ciphertext' => true,
        'client_secret' => true,
        'cookie' => true,
        'date_of_birth' => true,
        'document_number' => true,
        'encrypted_dek' => true,
        // PLT-PII-001: SENSITIVE_PII traveler document/visa/residence permit data (TravelerDocument, Visa, ResidencePermit).
        'document_type' => true,
        'held_visas' => true,
        'nationalities' => true,
        'nationality' => true,
        'residence_permits' => true,
        'travel_documents' => true,
        'email' => true,
        'passport_number' => true,
        'password' => true,
        'passwd' => true,
        'phone' => true,
        'phone_number' => true,
        'private_key' => true,
        'proxy_authorization' => true,
        'refresh_token' => true,
        'revision_id' => true,
        'secret' => true,
        'set_cookie' => true,
        'token' => true,
        'trip_id' => true,
        'x_api_key' => true,
        'x_diagnostics_token' => true,
    ];

    /** Normalized context keys written as a stable pseudonym, so records stay joinable without the raw id. */
    private const PSEUDONYMIZED_KEYS = [
        'traveler_id' => true,
    ];

    /** Values shorter than this are not scrubbed by value (too likely to match ordinary text). */
    private const MIN_SECRET_LENGTH = 4;

    /** @var list<string> */
    private array $secretValues;

    /**
     * @param  list<mixed>  $secretValues  configured credentials (provider tokens, usernames used as API keys, ...)
     *                                     that must not appear anywhere in a record, whatever key or text carries them
     * @param  (Closure(string): ?string)|null  $pseudonym  null result or no closure redacts the value instead
     */
    public function __construct(array $secretValues = [], private readonly ?Closure $pseudonym = null)
    {
        $values = array_values(array_unique(array_filter(
            $secretValues,
            fn (mixed $value): bool => is_string($value) && strlen(trim($value)) >= self::MIN_SECRET_LENGTH,
        )));
        // Longest first so a secret containing another secret is replaced as a whole.
        usort($values, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $this->secretValues = $values;
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->scrub($record->message),
            context: $this->redact($record->context),
            extra: $this->redact($record->extra),
        );
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && isset(self::PSEUDONYMIZED_KEYS[str_replace('-', '_', strtolower($key))])) {
                $values[$key] = $value === null ? null : $this->pseudonymize($value);

                continue;
            }
            if (is_string($key) && isset(self::SENSITIVE_KEYS[str_replace('-', '_', strtolower($key))])) {
                $values[$key] = self::REDACTED;

                continue;
            }

            $values[$key] = match (true) {
                is_array($value) => $this->redact($value),
                is_string($value) => $this->scrub($value),
                $value instanceof Throwable => $this->throwable($value),
                default => $value,
            };
        }

        return $values;
    }

    private function pseudonymize(mixed $value): string
    {
        $pseudonym = is_string($value) && $this->pseudonym !== null ? ($this->pseudonym)($value) : null;

        return $pseudonym ?? self::REDACTED;
    }

    private function scrub(string $text): string
    {
        return $this->secretValues === [] ? $text : str_replace($this->secretValues, self::REDACTED, $text);
    }

    /**
     * An exception is kept as-is unless its messages or trace carry a configured secret; then only its class and
     * location are logged with the scrubbed message (the trace, which may hold call arguments, is dropped).
     *
     * @return Throwable|array<string, mixed>
     */
    private function throwable(Throwable $exception): Throwable|array
    {
        $texts = [];
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $texts[] = $current->getMessage();
            $texts[] = $current->getTraceAsString();
        }
        $joined = implode("\n", $texts);
        if ($this->scrub($joined) === $joined) {
            return $exception;
        }

        return [
            'class' => $exception::class,
            'message' => $this->scrub($exception->getMessage()),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'previous' => $exception->getPrevious() === null ? null : $exception->getPrevious()::class,
            'redaction' => 'configured secret removed; trace omitted',
        ];
    }
}
