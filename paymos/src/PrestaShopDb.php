<?php

declare(strict_types=1);

namespace PaymosPrestaShop;

/**
 * DbInterface implementation over PrestaShop's \Db singleton.
 *
 * The only subtlety is insert(): the dedup EventStore relies on a duplicate-key
 * INSERT returning false (not throwing). \Db::insert() already returns false on
 * failure, but PrestaShop may be configured to throw on SQL errors
 * (PS_DEBUG_SQL). We therefore catch \PrestaShopDatabaseException and a duplicate
 * MySQL error number and translate them to a false return, so a dedup race-loser
 * is handled gracefully on every host.
 */
final class PrestaShopDb implements DbInterface
{
    /** MySQL "Duplicate entry for key" error number. */
    private const ERR_DUP_ENTRY = 1062;

    /** @var \Db */
    private $db;

    public function __construct($db = null)
    {
        $this->db = $db !== null ? $db : \Db::getInstance();
    }

    public function execute($sql)
    {
        return (bool) $this->db->execute($sql);
    }

    public function getValue($sql)
    {
        $value = $this->db->getValue($sql);

        return $value === false || $value === null ? '' : (string) $value;
    }

    public function getRow($sql)
    {
        $row = $this->db->getRow($sql);

        return is_array($row) && count($row) > 0 ? $row : null;
    }

    public function getRows($sql)
    {
        $rows = $this->db->executeS($sql);

        return is_array($rows) ? $rows : array();
    }

    /**
     * Duplicate-key inserts must report false, never throw: that is how the webhook
     * event store recognises a redelivery. PrestaShop 8 raises a plain
     * PrestaShopException here rather than the PrestaShopDatabaseException one would
     * expect, so catching only the latter let every repeated webhook escape as a fatal
     * — a 500 that Paymos then retries forever. PrestaShopDatabaseException extends
     * PrestaShopException, so the wider catch covers both.
     */
    public function insert($table, array $row)
    {
        try {
            return (bool) $this->db->insert($table, $row, false, true, \Db::INSERT, false);
        } catch (\PrestaShopException $e) {
            if ((int) $this->db->getNumberError() === self::ERR_DUP_ENTRY) {
                return false;
            }

            throw $e;
        }
    }

    public function escape($value)
    {
        return pSQL((string) $value);
    }
}
