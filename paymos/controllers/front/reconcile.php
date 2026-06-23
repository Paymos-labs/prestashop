<?php
/**
 * Paymos reconcile controller — safety net for missed webhooks (HTTP entry).
 *
 * Selects orders still awaiting Paymos payment, pulls each invoice from the API,
 * and funnels the result through the same OrderMapper used by the webhook path
 * (reverse-verify + AmountGuard + roll-back guard all still apply). Throttled to
 * 50 invoices per 24h window.
 *
 * This is a ModuleFrontController, NOT a directly-callable script under modules/:
 * PrestaShop 9.0 denies direct access to .php files in the modules directory
 * (modules/.htaccess), so the cron must be reachable as a front-controller URL.
 * Trigger it from cron over HTTP with the secure token:
 *   https://shop.example.com/index.php?fc=module&module=paymos&controller=reconcile&token=...
 * The `token` query parameter must equal Tools::encrypt('paymos/reconcile') (the
 * secure token shown in the module's admin panel) so it is not publicly triggerable.
 *
 * @author    Paymos
 * @copyright Paymos
 * @license   https://opensource.org/licenses/MIT MIT License
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class PaymosReconcileModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ssl = true;

    /** Server-to-server cron call: no customer auth, no maintenance gate. */
    public $auth = false;

    public function initContent()
    {
        if (!$this->module->active) {
            $this->respond(404, 'Paymos module is not active.');
        }

        // Token gate: the HTTP entry must carry the module's secure token so it is
        // not publicly triggerable.
        $expected = Tools::encrypt('paymos/reconcile');
        if (!hash_equals($expected, (string) Tools::getValue('token'))) {
            $this->respond(403, 'Forbidden');
        }

        try {
            \PaymosPrestaShop\Migrations::install(new \PaymosPrestaShop\PrestaShopDb());

            $count = (new \PaymosPrestaShop\Reconciler(
                new \PaymosPrestaShop\InvoiceStore(new \PaymosPrestaShop\PrestaShopDb()),
                new \PaymosPrestaShop\PrestaShopAdapter()
            ))->run($this->module->paymosSettings());

            $this->respond(200, 'Paymos reconcile complete. Orders updated: ' . (int) $count . "\n");
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('[Paymos] Reconcile failed: ' . $e->getMessage(), 3, null, 'PaymosPrestaShop');
            $this->respond(500, 'Paymos reconcile failed: ' . $e->getMessage() . "\n");
        }
    }

    private function respond($statusCode, $body)
    {
        http_response_code((int) $statusCode);
        header('Content-Type: text/plain; charset=utf-8');
        echo $body;
        exit;
    }
}
