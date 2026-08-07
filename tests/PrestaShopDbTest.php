<?php

declare(strict_types=1);

use PaymosPrestaShop\PrestaShopDb;

// PrestaShop 8 raises a plain PrestaShopException on a duplicate key, not the
// PrestaShopDatabaseException the name suggests. Catching only the narrow class let
// every redelivered webhook escape as a fatal 500 — which Paymos then retries, so a
// single duplicate became an endless retry loop. Observed live on 2026-08-05, see
// .claude/memory/catalog/plugins/live-cms-test-log.md, finding L-9.

if (!class_exists('PrestaShopException')) {
    class PrestaShopException extends \Exception
    {
    }
}
if (!class_exists('PrestaShopDatabaseException')) {
    class PrestaShopDatabaseException extends PrestaShopException
    {
    }
}
if (!class_exists('Db')) {
    class Db
    {
        const INSERT = 1;
    }
}

final class PaymosFakePrestaShopDb
{
    /** @var \Throwable|null */
    public $throwOnInsert = null;

    /** @var int */
    public $numberError = 0;

    public function insert($table, $row, $nullValues = false, $useCache = true, $type = 1, $addPrefix = true)
    {
        if ($this->throwOnInsert !== null) {
            throw $this->throwOnInsert;
        }

        return true;
    }

    public function getNumberError()
    {
        return $this->numberError;
    }
}

function test_prestashop_db_reports_a_duplicate_key_as_false_not_a_fatal()
{
    foreach (array('PrestaShopException', 'PrestaShopDatabaseException') as $exceptionClass) {
        $fake = new PaymosFakePrestaShopDb();
        $fake->throwOnInsert = new $exceptionClass('Duplicate entry');
        $fake->numberError = 1062;

        $result = (new PrestaShopDb($fake))->insert('ps_paymos_webhook_event', array('event_id' => 'evt_1'));

        assertSameValue(
            false,
            $result,
            $exceptionClass . ' on a duplicate key must be reported as false so the webhook is treated as a redelivery.'
        );
    }
}

function test_prestashop_db_still_rethrows_a_real_database_failure()
{
    $fake = new PaymosFakePrestaShopDb();
    $fake->throwOnInsert = new PrestaShopException('Table is missing');
    $fake->numberError = 1146;

    $threw = false;
    try {
        (new PrestaShopDb($fake))->insert('ps_paymos_webhook_event', array('event_id' => 'evt_2'));
    } catch (PrestaShopException $e) {
        $threw = true;
    }

    assertTrueValue($threw, 'A failure that is not a duplicate key must not be swallowed.');
}
