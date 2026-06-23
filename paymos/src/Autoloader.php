<?php

declare(strict_types=1);

namespace PaymosPrestaShop;

/**
 * Registers a PSR-4 style autoloader for the PrestaShop module classes
 * (PaymosPrestaShop\) and the vendored Paymos PHP SDK (Paymos\).
 *
 * The SDK is vendored into the module by the dashboard build script
 * (scripts/build-prestashop-plugin.ps1) under
 * paymos/vendor/paymos/php-sdk/src — the merchant never runs Composer.
 */
final class Autoloader
{
    /** @var bool */
    private static $registered = false;

    public static function register()
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        spl_autoload_register(static function ($class) {
            $prefix = 'PaymosPrestaShop\\';
            if (strncmp($class, $prefix, strlen($prefix)) === 0) {
                $relative = substr($class, strlen($prefix));
                $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
                if (is_file($path)) {
                    require_once $path;
                }

                return;
            }

            $sdkPrefix = 'Paymos\\';
            if (strncmp($class, $sdkPrefix, strlen($sdkPrefix)) !== 0) {
                return;
            }

            $relative = substr($class, strlen($sdkPrefix));
            $localVendor = dirname(__DIR__) . '/vendor/paymos/php-sdk/src/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($localVendor)) {
                require_once $localVendor;
            }
        });
    }
}
