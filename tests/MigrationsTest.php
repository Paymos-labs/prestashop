<?php

declare(strict_types=1);

use PaymosPrestaShop\Migrations;
use PaymosPrestaShop\PrestaShopDb;

// Emulates the observed PS8.2/9.0 scalar-helper behavior, not a MySQL server.
// The release-native gate separately exercises the same migration on real SQL.
final class LegacyPrestaShopMetadataDb
{
    public $columns;
    public $paymentUrlType;
    public $alters = array();
    public $ddl = array();

    public function __construct(array $missing, string $paymentUrlType = 'mediumtext')
    {
        $this->columns = array('owner_token' => true, 'paid_late_noted' => true, 'payment_url' => true);
        $this->paymentUrlType = $paymentUrlType;
        foreach ($missing as $column) {
            $this->columns[$column] = false;
        }
    }

    public function getValue($sql)
    {
        // Actual failing native query: SHOW COLUMNS ... LIKE 'owner_token' LIMIT 1.
        throw new RuntimeException('Legacy scalar helper generated invalid metadata SQL: ' . $sql . ' LIMIT 1');
    }

    public function executeS($sql)
    {
        if (!preg_match('/^SHOW COLUMNS FROM `[^`]+` LIKE \'([^\']+)\'$/', $sql, $match)) {
            throw new RuntimeException('Unexpected metadata query: ' . $sql);
        }
        if (empty($this->columns[$match[1]])) {
            return array();
        }
        if ($match[1] === 'payment_url') {
            return array(array('Field' => 'payment_url', 'Type' => $this->paymentUrlType));
        }
        return array(array('Field' => $match[1]));
    }

    public function execute($sql)
    {
        $this->ddl[] = $sql;
        if (preg_match('/^ALTER TABLE `[^`]+` ADD COLUMN `([^`]+)`/', $sql, $match)) {
            if (!empty($this->columns[$match[1]])) {
                throw new RuntimeException('Duplicate column: ' . $match[1]);
            }
            $this->columns[$match[1]] = true;
            $this->alters[] = $match[1];
        } elseif (preg_match('/^ALTER TABLE `[^`]+` MODIFY COLUMN `payment_url` MEDIUMTEXT NOT NULL$/', $sql)) {
            $this->paymentUrlType = 'mediumtext';
            $this->alters[] = 'payment_url:mediumtext';
        }
        return true;
    }
}

function test_prestashop_migrations_work_with_native_scalar_limit_behavior()
{
    foreach (array(array(), array('owner_token'), array('paid_late_noted'), array('owner_token', 'paid_late_noted')) as $missing) {
        $native = new LegacyPrestaShopMetadataDb($missing);
        $adapter = new PrestaShopDb($native);
        assertTrueValue(Migrations::install($adapter), 'Existing and legacy schemas migrate successfully.');
        assertSameValue($missing, $native->alters, 'Only missing columns are added.');
        assertTrueValue($native->columns['owner_token'] && $native->columns['paid_late_noted'], 'Both required columns exist.');
        assertTrueValue(Migrations::install($adapter), 'A repeat is safe.');
        assertSameValue($missing, $native->alters, 'A repeat never adds an existing column.');
    }

    // BUG-1434: fresh installs create payment_url as MEDIUMTEXT, and legacy TEXT columns widen idempotently.
    $legacy = new LegacyPrestaShopMetadataDb(array(), 'text');
    $adapter = new PrestaShopDb($legacy);
    assertTrueValue(Migrations::install($adapter), 'Legacy TEXT payment_url migrates to MEDIUMTEXT.');
    assertContainsValue('`payment_url` MEDIUMTEXT NOT NULL', $legacy->ddl[0], 'Fresh CREATE TABLE defines payment_url as MEDIUMTEXT NOT NULL.');
    assertSameValue(array('payment_url:mediumtext'), $legacy->alters, 'Legacy TEXT payment_url runs one MODIFY COLUMN ALTER.');
    assertSameValue('mediumtext', $legacy->paymentUrlType, 'Legacy payment_url is widened to mediumtext.');
    assertTrueValue(Migrations::install($adapter), 'Repeat install after widening is idempotent.');
    assertSameValue(array('payment_url:mediumtext'), $legacy->alters, 'Widened MEDIUMTEXT payment_url is not altered again.');
}
