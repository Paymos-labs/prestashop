<?php

declare(strict_types=1);

namespace PaymosPrestaShop;

/** Refund reads always use the master connection without the query cache. */
final class RefundDatabase implements DbInterface
{
    private $db;

    public function __construct($db = null)
    {
        $this->db = $db ?: \Db::getInstance();
    }

    public function execute($sql)
    {
        if (!$this->db->execute($sql)) {
            throw new \RuntimeException('refund_database_write_failed');
        }
        return true;
    }

    public function getRows($sql)
    {
        $rows = $this->db->executeS($sql, true, false);
        if (!is_array($rows)) {
            throw new \RuntimeException('refund_database_read_failed');
        }
        return $rows;
    }

    public function getRow($sql)
    {
        $rows = $this->getRows($sql);
        return count($rows) ? $rows[0] : null;
    }

    public function getValue($sql)
    {
        $row = $this->getRow($sql);
        return $row === null ? '' : (string) reset($row);
    }

    public function insert($table, array $row)
    {
        if (!$this->db->insert($table, $row, true, false, \Db::INSERT, false)) {
            throw new \RuntimeException('refund_database_insert_failed');
        }
        return true;
    }

    public function escape($value)
    {
        return $this->db->escape((string) $value, true);
    }
}
