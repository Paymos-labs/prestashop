<?php

declare(strict_types=1);

namespace PaymosPrestaShop;

/**
 * The PHP floor the module refuses to install below (BUG-187).
 *
 * The vendored SDK declares PHP 7.4 (plugins/php-sdk/composer.json) and was
 * never run on anything older. The PrestaShop floor that goes with it lives in
 * Paymos::__construct as ps_versions_compliancy.min = 1.7.8.0, the first release
 * PrestaShop's own compatibility table lists for PHP 7.4; 1.7.6 stops at 7.3.
 * PrestaShop core refuses an install outside that range, but 1.7.8 still runs
 * on PHP 7.1-7.3, so the PHP check is the module's own.
 */
final class Requirements
{
    const PHP_MIN = '7.4.0';

    /**
     * @param string $phpVersion A PHP_VERSION string.
     * @return bool
     */
    public static function phpSupported($phpVersion)
    {
        return version_compare((string) $phpVersion, self::PHP_MIN, '>=');
    }
}
