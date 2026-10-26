<?php

declare(strict_types=1);

function test_module_description_resolves_in_every_translation_catalog()
{
    $description = 'Add Paymos at checkout, track payment states in your back office, and accept orders after payment verification.';
    $source = file_get_contents(PAYMOS_PRESTASHOP_MODULE_DIR . 'paymos.php');
    assertContainsValue("\$this->description = \$this->l('" . $description . "');", $source, 'Description fixture matches the actual source.');
    $key = '<{paymos}prestashop>paymos_' . md5($description);
    foreach (array('en', 'ru', 'de', 'es', 'tr', 'zh-CN') as $locale) {
        $_MODULE = array();
        require PAYMOS_PRESTASHOP_MODULE_DIR . 'translations/' . $locale . '.php';
        assertTrueValue(isset($_MODULE[$key]) && trim($_MODULE[$key]) !== '', 'Description resolves in ' . $locale . '.');
        if ($locale === 'en') {
            assertSameValue($description, $_MODULE[$key], 'English description is the source value.');
        }
        assertFalseValue(isset($_MODULE['<{paymos}prestashop>paymos_31f7b3073a3874c1d83eb15b16611a9c']), 'Obsolete description key removed in ' . $locale . '.');
    }
}
