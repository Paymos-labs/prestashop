<?php

declare(strict_types=1);

use PaymosPrestaShop\InvoiceStore;
use PaymosPrestaShop\Migrations;

/**
 * invoice-late-payment, late-44: the paid-late marker lives in the shop's own table, so a redelivery after a
 * crash (note written, dedup not yet committed) still sees it. Runs the real InvoiceStore/Migrations SQL
 * against a recording database.
 */
final class FakePrestaShopStoreDb implements PaymosPrestaShop\DbInterface
{
    /** @var array<int, string> */
    public $executed = array();
    /** @var array<int, array<string, mixed>> */
    public $inserted = array();
    /** @var bool */
    public $hasMarkerColumn = false;
    /** @var array<string, mixed>|null */
    public $existing = null;

    public function execute($sql)
    {
        $this->executed[] = (string) $sql;
        return true;
    }

    public function getValue($sql)
    {
        return '';
    }

    public function getRow($sql)
    {
        return $this->existing;
    }

    public function getRows($sql)
    {
        if ($this->hasMarkerColumn && preg_match('/SHOW COLUMNS.+LIKE \'([^\']+)\'/', $sql, $match)) {
            return array(array('Field' => $match[1]));
        }
        return array();
    }

    public function insert($table, array $row)
    {
        $this->inserted[] = $row;
        return true;
    }

    public function escape($value)
    {
        return addslashes((string) $value);
    }

    public function last($prefix)
    {
        foreach (array_reverse($this->executed) as $sql) {
            if (stripos(ltrim($sql), $prefix) === 0) {
                return $sql;
            }
        }

        return '';
    }
}

function paymos_prestashop_store_row(array $extra = array())
{
    return $extra + array(
        'id_order' => 7,
        'id_cart' => 3,
        'paymos_invoice_id' => 'inv_late',
        'external_order_id' => 'ps-7',
        'environment' => 'live',
        'project_id' => 'prj_1',
        'amount' => '100.00',
        'currency' => 'USD',
        'payment_url' => 'https://pay.paymos.test/inv_late',
        'status' => 'expired',
        'renew_count' => 0,
    );
}

function test_prestashop_store_adds_the_paid_late_column_once()
{
    $old = new FakePrestaShopStoreDb();
    Migrations::install($old);
    assertContainsValue('ADD COLUMN `paid_late_noted`', $old->last('ALTER TABLE'), 'an old table gains the marker column.');

    $current = new FakePrestaShopStoreDb();
    $current->hasMarkerColumn = true;
    Migrations::install($current);
    assertSameValue('', $current->last('ALTER TABLE'), 'a table that has the column is not altered again.');
}

function test_prestashop_store_persists_the_paid_late_marker()
{
    $update = new FakePrestaShopStoreDb();
    $update->existing = paymos_prestashop_store_row();
    (new InvoiceStore($update))->save(paymos_prestashop_store_row(array('paid_late_noted' => 'evt_late_1')));
    assertContainsValue("`paid_late_noted` = 'evt_late_1'", $update->last('UPDATE'), 'the marker is written on update.');

    $insert = new FakePrestaShopStoreDb();
    (new InvoiceStore($insert))->save(paymos_prestashop_store_row(array('paid_late_noted' => 'evt_late_1')));
    assertSameValue('evt_late_1', $insert->inserted[0]['paid_late_noted'], 'the marker is written on insert.');
}

function test_prestashop_store_save_without_the_marker_leaves_it_alone()
{
    $db = new FakePrestaShopStoreDb();
    $db->existing = paymos_prestashop_store_row(array('paid_late_noted' => 'evt_late_1'));

    (new InvoiceStore($db))->save(paymos_prestashop_store_row());

    assertSameValue(false, strpos($db->last('UPDATE'), 'paid_late_noted'), 'a save that does not carry the marker does not touch it.');
}
