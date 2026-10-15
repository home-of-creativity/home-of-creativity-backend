<?php

namespace App\Support;

use RuntimeException;

/**
 * The testers' copy runs with APP_ENV=staging on its own database. Each side refuses to boot
 * on the other's database, so a copied .env cannot point testing at live data or the reverse.
 */
class StagingGuard
{
    public const ENVIRONMENT = 'staging';

    public const DATABASE = 'hoc_testing';

    public static function assertDatabase(string $environment, ?string $database): void
    {
        $database = trim((string) $database);

        if ($environment === self::ENVIRONMENT && $database !== self::DATABASE) {
            throw new RuntimeException('Staging must use the '.self::DATABASE.' database. Refusing to start.');
        }

        if ($environment === 'production' && $database === self::DATABASE) {
            throw new RuntimeException('Production cannot use the '.self::DATABASE.' database. Refusing to start.');
        }
    }

    public static function isStagingDatabase(string $environment, ?string $database): bool
    {
        return $environment === self::ENVIRONMENT && trim((string) $database) === self::DATABASE;
    }
}
