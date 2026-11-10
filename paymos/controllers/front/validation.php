<?php
/**
 * Paymos checkout → invoice controller.
 *
 * Creates the PrestaShop order in the "Awaiting Paymos payment" state (so a
 * stable id_order exists to key the invoice on), mints a Paymos invoice via the
 * SDK, stores the snapshot, then redirects the buyer to the Paymos hosted
 * checkout. Nothing is marked paid here — only the verified webhook transitions
 * the order.
 *
 * @author    Paymos
 * @copyright Paymos
 * @license   https://opensource.org/licenses/MIT MIT License
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class PaymosValidationModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ssl = true;

    public function postProcess()
    {
        $cart = $this->context->cart;

        if (
            $cart->id_customer == 0
            || $cart->id_address_delivery == 0
            || $cart->id_address_invoice == 0
            || !$this->module->active
        ) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        if (!$this->paymentIsAvailable()) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $customer = new Customer((int) $cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        // Reuse the customer's existing Paymos order and run the same protected
        // recovery/renewal path; never create a second native order for the cart.
        if ($cart->orderExists()) {
            $orderId = (int) Order::getIdByCartId((int) $cart->id);
            $existingOrder = new Order($orderId);
            if (!Validate::isLoadedObject($existingOrder)
                || $existingOrder->module !== $this->module->name
                || (int) $existingOrder->id_customer !== (int) $customer->id
                || (string) $existingOrder->secure_key !== (string) $customer->secure_key) {
                Tools::redirect('index.php?controller=history');
                return;
            }
            if ($existingOrder->hasBeenPaid()) {
                $this->redirectToPending($orderId, $cart, $customer);
                return;
            }
        } else {
            $currency = $this->context->currency;
            $total = (float) $cart->getOrderTotal(true, Cart::BOTH);
            $pendingStateId = (int) Configuration::get('PAYMOS_OS_PENDING');

            // Create the order up front in the awaiting state. Paid amount is set from
            // the verified webhook later, never from the cart at this point.
            $this->module->validateOrder(
                (int) $cart->id,
                $pendingStateId,
                $total,
                $this->module->displayName,
                null,
                array(),
                (int) $currency->id,
                false,
                $customer->secure_key
            );

            $orderId = (int) $this->module->currentOrder;
            if ($orderId <= 0) {
                $this->failOrderCreation();
            }
        }

        try {
            \PaymosPrestaShop\Migrations::install(new \PaymosPrestaShop\PrestaShopDb());

            $result = (new \PaymosPrestaShop\GatewayCheckout(
                new \PaymosPrestaShop\InvoiceStore(new \PaymosPrestaShop\PrestaShopDb()),
                new \PaymosPrestaShop\PrestaShopAdapter()
            ))->start($orderId, $this->module->paymosSettings());
        } catch (\Paymos\Plugin\InvoiceReplacementBlockedException $e) {
            // The order's previous Paymos invoice is still payable (or could not
            // be proven closed), so no second invoice was cut and the order is
            // already in the manual-review state. Failing it here would hide a
            // payment that may still arrive on the old invoice.
            PrestaShopLogger::addLog('[Paymos] ' . $e->result()->summary(), 2, null, 'PaymosPrestaShop');
            // Say so explicitly: the manual-review state alone is also what a
            // post-payment amount mismatch leaves behind (BUG-190).
            $this->redirectToPending($orderId, $cart, $customer, true);

            return;
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('[Paymos] Checkout failed: ' . $e->getMessage(), 3, null, 'PaymosPrestaShop');
            $this->failStrandedOrder($orderId, $cart, $customer);

            return;
        }

        if (empty($result['payment_url'])) {
            PrestaShopLogger::addLog('[Paymos] Checkout produced no payment URL for order ' . (int) $orderId . '.', 3, null, 'PaymosPrestaShop');
            $this->failStrandedOrder($orderId, $cart, $customer);

            return;
        }

        Tools::redirect($result['payment_url']);
    }

    /**
     * Invoice creation failed AFTER the order was already created in the awaiting
     * state, and no snapshot row exists for the reconciler to recover it. Keep the
     * order pending and show the buyer the status page. A retry
     * reuses this native order and recovers the deterministic invoice intent.
     * Browser checkout failures never overwrite an authoritative payment state.
     */
    private function failStrandedOrder($orderId, $cart, $customer)
    {
        $this->redirectToPending($orderId, $cart, $customer);
    }

    /**
     * @param bool $replacementBlocked The invoice replacement was blocked: the
     *                                 page asks the buyer to contact the store
     *                                 and offers no payment link.
     */
    private function redirectToPending($orderId, $cart, $customer, $replacementBlocked = false)
    {
        $params = array(
            'id_order' => (int) $orderId,
            'id_cart' => (int) $cart->id,
            'key' => $customer->secure_key,
        );
        if ($replacementBlocked) {
            $params[\PaymosPrestaShop\PendingPage::REVIEW_PARAM] = '1';
        }

        Tools::redirect($this->context->link->getModuleLink(
            $this->module->name,
            'pending',
            $params,
            true
        ));
    }

    private function paymentIsAvailable()
    {
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] === $this->module->name) {
                return true;
            }
        }

        return false;
    }

    private function failOrderCreation()
    {
        PrestaShopLogger::addLog('[Paymos] validateOrder did not produce an order id.', 3, null, 'PaymosPrestaShop');
        Tools::redirect('index.php?controller=order&step=1');
    }
}
