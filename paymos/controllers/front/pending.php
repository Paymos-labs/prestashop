<?php
/**
 * Paymos status page for the buyer.
 *
 * Reached when checkout could not hand off to the Paymos hosted checkout (the
 * invoice could not be created): the order was already moved to the payment-error
 * state, and this page explains that clearly. It is also a safe neutral target
 * for a buyer who returns to the shop while the (authoritative) webhook is still
 * in flight. This page is read-only: it NEVER calls validateOrder() and never
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

        // A failed hand-off left the order in the payment-error state; render the
        // failure message instead of the neutral "processing" one.
        $failed = ((int) $order->getCurrentState() === (int) Configuration::get('PS_OS_ERROR'));

        // Resume-payment link: if the buyer abandoned the hosted checkout but the
        // invoice is still open (non-terminal), re-surface the stored payment URL so
        // they can finish paying the SAME invoice. allow_multiple_payments is true
        // and the invoice TTL is server-side, so re-offering the stored URL is safe.
        $resumeUrl = '';
        if (!$failed) {
            try {
                $invoice = (new \PaymosPrestaShop\InvoiceStore(new \PaymosPrestaShop\PrestaShopDb()))
                    ->findByOrderId($orderId);
                if (
                    is_array($invoice)
                    && !empty($invoice['payment_url'])
                    && $this->invoiceIsResumable(isset($invoice['status']) ? (string) $invoice['status'] : '')
                ) {
                    $resumeUrl = (string) $invoice['payment_url'];
                }
            } catch (\Throwable $e) {
                // Never let a lookup error break the status page — just omit the link.
                $resumeUrl = '';
            }
        }

        $this->context->smarty->assign(array(
            'paymos_failed' => $failed,
            'paymos_order_reference' => $order->reference,
            'paymos_history_url' => $this->context->link->getPageLink('history'),
            'paymos_resume_url' => $resumeUrl,
        ));

        $this->setTemplate('module:paymos/views/templates/front/pending.tpl');
    }

    /**
     * A stored invoice is resumable only while it is still open — i.e. not in any
     * terminal status. Once paid/underpaid/expired/cancelled, re-offering the pay
     * URL would be misleading.
     */
    private function invoiceIsResumable($status)
    {
        $terminal = array('paid', 'paid_over', 'underpaid', 'expired', 'cancelled');

        return $status !== '' && !in_array(strtolower(trim($status)), $terminal, true);
    }
}
