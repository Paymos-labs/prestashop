<?php
/**
 * Paymos status page for the buyer.
 *
 * Reached when checkout could not hand off to the Paymos hosted checkout (the
 * invoice could not be created): the order was already moved to the payment-error
 * state, and this page explains that clearly. It is also a safe neutral target
 * for a buyer who returns to the shop while the (authoritative) webhook is still
 * in flight, and the page that asks the buyer to contact the store when a
 * blocked invoice replacement put the order under review (BUG-190). This page
 * is read-only: it NEVER calls validateOrder() and never
 * transitions the order — that is the callback's job.
 *
 * @author    Paymos
 * @copyright Paymos
 * @license   https://opensource.org/licenses/MIT MIT License
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class PaymosPendingModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ssl = true;

    public function initContent()
    {
        parent::initContent();

        $orderId = (int) Tools::getValue('id_order');
        $key = (string) Tools::getValue('key');
        $order = new Order($orderId);

        if (
            !Validate::isLoadedObject($order)
            || $order->module !== $this->module->name
            || $order->secure_key !== $key
        ) {
            Tools::redirect('index.php?controller=history');
        }

        // If the webhook already landed and marked the order paid, send the buyer
        // straight to the normal confirmation page.
        if ($order->hasBeenPaid()) {
            $customer = new Customer((int) $order->id_customer);
            Tools::redirect('index.php?controller=order-confirmation&id_cart=' . (int) $order->id_cart
                . '&id_module=' . (int) $this->module->id
                . '&id_order=' . (int) $order->id
                . '&key=' . $customer->secure_key);
        }

        // Which of the three texts to show, and whether the stored invoice may be
        // re-offered, is decided by PendingPage: a failed hand-off (PS_OS_ERROR),
        // a blocked invoice replacement (validation.php's explicit flag — the
        // manual-review state alone is ambiguous, BUG-190), or the neutral
        // "processing" text with a resume link for a buyer who closed the Paymos
        // checkout too soon (allow_multiple_payments is true and the invoice TTL is
        // server-side, so re-offering the stored, still open invoice is safe).
        $invoice = null;
        try {
            $invoice = (new \PaymosPrestaShop\InvoiceStore(new \PaymosPrestaShop\PrestaShopDb()))
                ->findByOrderId($orderId);
        } catch (\Throwable $e) {
            // Never let a lookup error break the status page — just omit the link.
            $invoice = null;
        }

        $page = \PaymosPrestaShop\PendingPage::decide(
            (int) $order->getCurrentState(),
            (int) Configuration::get('PS_OS_ERROR'),
            (int) Configuration::get('PAYMOS_OS_MANUAL_REVIEW'),
            (string) Tools::getValue(\PaymosPrestaShop\PendingPage::REVIEW_PARAM) === '1',
            is_array($invoice) ? $invoice : null
        );

        $this->context->smarty->assign(array(
            'paymos_failed' => $page['view'] === \PaymosPrestaShop\PendingPage::VIEW_FAILED,
            'paymos_review' => $page['view'] === \PaymosPrestaShop\PendingPage::VIEW_REVIEW,
            'paymos_order_reference' => $order->reference,
            'paymos_history_url' => $this->context->link->getPageLink('history'),
            'paymos_resume_url' => $page['resume_url'],
        ));

        $this->setTemplate('module:paymos/views/templates/front/pending.tpl');
    }
}
