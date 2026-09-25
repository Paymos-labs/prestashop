<?php

declare(strict_types=1);

use PaymosPrestaShop\Requirements;

// BUG-187: PrestaShop 1.7.8 still runs on PHP 7.1-7.3, below the module's floor.

function test_requirements_refuse_php_below_7_4()
{
    foreach (array('7.1.2', '7.2.34', '7.3.33', '7.3.99') as $version) {
        assertFalseValue(Requirements::phpSupported($version), 'PHP ' . $version . ' is below the floor.');
    }
}

function test_requirements_accept_php_7_4_and_later()
{
    foreach (array('7.4.0', '7.4.33', '8.0.30', '8.1.2', PHP_VERSION) as $version) {
        assertTrueValue(Requirements::phpSupported($version), 'PHP ' . $version . ' meets the floor.');
    }
}

function test_install_checks_php_before_parent_install()
{
    $source = (string) file_get_contents(PAYMOS_PRESTASHOP_MODULE_DIR . 'paymos.php');
    $guard = strpos($source, 'Requirements::phpSupported(PHP_VERSION)');
    $parent = strpos($source, 'parent::install()');
    assertTrueValue($guard !== false && $parent !== false && $guard < $parent, 'install() checks PHP before PrestaShop writes anything.');
    assertContainsValue("'min' => '1.7.8.0'", $source, 'ps_versions_compliancy starts at the first PrestaShop that runs PHP 7.4.');
}
