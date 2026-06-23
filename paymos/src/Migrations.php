<?php

declare(strict_types=1);

namespace PaymosPrestaShop;

/**
 * Owns the two module tables. `event_id` is the PRIMARY KEY of the dedup table —
 * a unique-key insert is the only race-proof dedup primitive, exactly as the
 * Paymos plugin canon mandates.
 *
 * The constructor takes a DB handle exposing execute()/getValue()/insert helpers
 * (the PrestaShopAdapter wraps PrestaShop's \Db) so the tables can be created on
 * install and lazily ensured on the checkout/callback paths.
 */
final class Migrations
{
    public const INVOICES_TABLE = 'paymos_invoice';
    public const EVENTS_TABLE = 'paymos_webhook_event';

    public static function install($db)
    {
        $invoiceTable = self::table(self::INVOICES_TABLE);
        $eventTable = self::table(self::EVENTS_TABLE);

        $db->execute('CREATE TABLE IF NOT EXISTS `' . $invoiceTable . '` (
            `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_order` INT(11) UNSIGNED NOT NULL,
            `id_cart` INT(11) UNSIGNED NOT NULL,
            `paymos_invoice_id` VARCHAR(128) NOT NULL,
            `external_order_id` VARCHAR(191) NOT NULL,
            `environment` VARCHAR(16) NOT NULL,
            `project_id` VARCHAR(128) NOT NULL,
            `amount` VARCHAR(64) NOT NULL,
            `currency` VARCHAR(16) NOT NULL,
            `payment_url` TEXT NOT NULL,
            `status` VARCHAR(64) NOT NULL,
            `renew_count` INT(11) UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME NULL,
            `updated_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `paymos_invoice_id` (`paymos_invoice_id`),
            UNIQUE KEY `external_order_id` (`external_order_id`),
            KEY `id_order` (`id_order`),
            KEY `id_cart` (`id_cart`),
            KEY `environment` (`environment`),
            KEY `project_id` (`project_id`),
            KEY `status` (`status`)
        ) ENGINE=' . self::engine() . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $db->execute('CREATE TABLE IF NOT EXISTS `' . $eventTable . '` (
            `event_id` VARCHAR(128) NOT NULL,
            `expires_at` INT(11) UNSIGNED NOT NULL,
            `created_at` INT(11) UNSIGNED NOT NULL,
            PRIMARY KEY (`event_id`),
            KEY `expires_at` (`expires_at`)
        ) ENGINE=' . self::engine() . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        return true;
    }

    public static function uninstall($db)
    {
        $db->execute('DROP TABLE IF EXISTS `' . self::table(self::EVENTS_TABLE) . '`');
        $db->execute('DROP TABLE IF EXISTS `' . self::table(self::INVOICES_TABLE) . '`');

        return true;
    }

    public static function table($name)
    {
        $prefix = defined('_DB_PREFIX_') ? constant('_DB_PREFIX_') : '';

        return $prefix . $name;
    }

    private static function engine()
    {
        return defined('_MYSQL_ENGINE_') ? constant('_MYSQL_ENGINE_') : 'InnoDB';
    }
}
