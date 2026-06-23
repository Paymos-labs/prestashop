<?php

declare(strict_types=1);

namespace PaymosPrestaShop;

/**
 * Narrow database contract the stores depend on. The PrestaShopDb adapter wraps
 * PrestaShop's \Db singleton; tests inject a tiny in-memory fake. Keeping this
 * thin (execute / getValue / getRow / getRows / insert / escape) means the store
 * logic is fully unit-testable without a live MySQL.
 */
interface DbInterface
{
    /**
     * @return bool
     */
    public function execute($sql);

    /**
     * @return string
     */
    public function getValue($sql);

    /**
     * @return array<string, mixed>|null
     */
    public function getRow($sql);

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRows($sql);

    /**
     * Insert a single row. Returns false on a duplicate-key collision (the dedup
     * race-loser) and true otherwise. Implementations MUST NOT throw on a
     * duplicate-key error — they translate it to a false return.
     *
     * @param array<string, scalar> $row
     * @return bool
     */
    public function insert($table, array $row);

    /**
     * @return string
     */
    public function escape($value);
}
