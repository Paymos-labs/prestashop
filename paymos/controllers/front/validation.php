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

        // Duplicate-order guard (PrestaShop validation requirement). If an order
        // already exists for this cart — buyer double-submitted or refreshed the
        // validation URL — do NOT mint a second order + second Paymos invoice;
        // send them to the confirmation of the order that already exists.
        if ($cart->orderExists()) {
            $existingOrderId = (int) Order::getIdByCartId((int) $cart->id);
            $this->redirectToPending($existingOrderId, $cart, $customer);

            return;
        }

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

        try {
            \PaymosPrestaShop\Migrations::install(new \PaymosPrestaShop\PrestaShopDb());

            $result = (new \PaymosPrestaShop\GatewayCheckout(
                new \PaymosPrestaShop\InvoiceStore(new \PaymosPrestaShop\PrestaShopDb()),
                new \PaymosPrestaShop\PrestaShopAdapter()
            ))->start($orderId, $this->module->paymosSettings());
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
     * state, and no snapshot row exists for the reconciler to recover it. Move the
     * order to the payment-error state (so it never lingers as a permanent
     * awaiting-payment order with no recovery path) and show the buyer the Paymos
     * status page, which renders the failure clearly. Nothing is marked paid.
     */
    private function failStrandedOrder($orderId, $cart, $customer)
    {
        try {
            (new \PaymosPrestaShop\PrestaShopAdapter())->setOrderState(
                (int) $orderId,
                (int) Configuration::get('PS_OS_ERROR')
            );
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('[Paymos] Could not fail stranded order ' . (int) $orderId . ': ' . $e->getMessage(), 3, null, 'PaymosPrestaShop');
        }

        $this->redirectToPending($orderId, $cart, $customer);
    }

    private function redirectToPending($orderId, $cart, $customer)
    {
        Tools::redirect($this->context->link->getModuleLink(
            $this->module->name,
            'pending',
            array(
                'id_order' => (int) $orderId,
                'id_cart' => (int) $cart->id,
                'key' => $customer->secure_key,
            ),
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
