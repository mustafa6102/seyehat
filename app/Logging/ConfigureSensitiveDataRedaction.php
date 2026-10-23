<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Logger as MonologLogger;
use Throwable;

/** PLT-PII-001: channel tap; Laravel passes its Logger wrapper, the processor is registered on the Monolog instance. */
final class ConfigureSensitiveDataRedaction
{
    /**
     * Configured credentials removed by value from every record ("Loglarda sır/PII sızıntısı yoktur"): provider
     * tokens and API keys, the GeoNames username (sent as a URL parameter), the diagnostics token, the app key and
     * the database password.
     */
    private const SECRET_CONFIG_KEYS = [
        'app.key',
        'diagnostics.token',
        'services.duffel.access_token',
        'services.geonames.username',
        'services.postmark.key',
        'services.resend.key',
        'services.ses.key',
        'services.ses.secret',
        'services.slack.notifications.bot_user_oauth_token',
    ];

    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();
        if ($monolog instanceof MonologLogger) {
            $monolog->pushProcessor(new RedactSensitiveContext($this->configuredSecrets()));
        }
    }

    /** @return list<mixed> */
    private function configuredSecrets(): array
    {
        try {
            $config = app('config');
            $secrets = array_map(fn (string $key): mixed => $config->get($key), self::SECRET_CONFIG_KEYS);
            $secrets[] = $config->get('database.connections.'.$config->get('database.default').'.password');

            return $secrets;
        } catch (Throwable) {
            // Without configuration only key-based redaction applies; logging itself must not fail.
            return [];
        }
    }
}
