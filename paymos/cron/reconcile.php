<?php
/**
 * Paymos reconcile cron — safety net for missed webhooks.
 *
 * Selects orders still awaiting Paymos payment, pulls each invoice from the API,
 * and funnels the result through the same OrderMapper used by the webhook path
 * (reverse-verify + AmountGuard + roll-back guard all still apply). Throttled to
 * 50 invoices per 24h window.
 *
 * Run from the PrestaShop root via cron (CLI), e.g.:
 *   php modules/paymos/cron/reconcile.php
 *
 * For HTTP-triggered cron use the front-controller URL instead — PrestaShop 9.0
 * denies direct access to .php files under modules/, so this file 403s over the
 * web on PS 9. The admin panel shows the PS-9-safe URL:
 *   index.php?fc=module&module=paymos&controller=reconcile&token=...
 *
 * This CLI entry's HTTP fallback still requires a `token` equal to
 * Tools::encrypt('paymos/reconcile') for stores on PS < 9 where direct .php
 * access is allowed. CLI runs need no token.
 *
 * @author    Paymos
 * @copyright Paymos
 * @license   https://opensource.org/licenses/MIT MIT License
 */

$root = dirname(__DIR__, 3);
require_once $root . '/config/config.inc.php';

require_once dirname(__DIR__) . '/src/Autoloader.php';
\PaymosPrestaShop\Autoloader::register();

$module = Module::getInstanceByName('paymos');
if (!Validate::isLoadedObject($module) || !$module->active) {
    http_response_code(404);
    exit('Paymos module is not installed or not active.');
}

// Over HTTP, require a token equal to the module's secure token. On CLI there is
// no token requirement.
if (PHP_SAPI !== 'cli') {
    $expected = Tools::encrypt('paymos/reconcile');
    if (!hash_equals($expected, (string) Tools::getValue('token'))) {
        http_response_code(403);
        exit('Forbidden');
    }
}

try {
    $count = (new \PaymosPrestaShop\Reconciler(
        new \PaymosPrestaShop\InvoiceStore(new \PaymosPrestaShop\PrestaShopDb()),
        new \PaymosPrestaShop\PrestaShopAdapter()
    ))->run($module->paymosSettings());

    echo 'Paymos reconcile complete. Orders updated: ' . (int) $count . "\n";
} catch (\Throwable $e) {
    PrestaShopLogger::addLog('[Paymos] Reconcile cron failed: ' . $e->getMessage(), 3, null, 'PaymosPrestaShop');
    http_response_code(500);
    echo 'Paymos reconcile failed: ' . $e->getMessage() . "\n";
}
