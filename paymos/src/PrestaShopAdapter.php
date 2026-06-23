<?php

declare(strict_types=1);

namespace PaymosPrestaShop;

/**
 * PrestaShopAdapterInterface implementation over real PrestaShop objects
 * (\Order, \OrderHistory, \Configuration, \PrestaShopLogger). Runtime-only — the
 * unit tests inject a fake adapter instead.
 *
 * Order-state ids for the Paymos-specific states (pending / confirming /
 * manual_review) are persisted in PrestaShop Configuration at module install
 * under PAYMOS_OS_* keys. paid/failed/cancelled map to PrestaShop core states
 * (PS_OS_PAYMENT / PS_OS_ERROR / PS_OS_CANCELED) so they stay consistent with the
 * rest of the shop.
 */
final class PrestaShopAdapter implements PrestaShopAdapterInterface
{
    /** Logical action key → PrestaShop Configuration key holding the state id. */
    private const STATE_KEYS = array(
        'pending' => 'PAYMOS_OS_PENDING',
        'confirming' => 'PAYMOS_OS_CONFIRMING',
        'manual_review' => 'PAYMOS_OS_MANUAL_REVIEW',
        'paid' => 'PS_OS_PAYMENT',
        'failed' => 'PS_OS_ERROR',
        'cancelled' => 'PS_OS_CANCELED',
    );

    public function getOrder($orderId)
    {
        $order = new \Order((int) $orderId);
        if (!\Validate::isLoadedObject($order)) {
            return array();
        }

        $currency = new \Currency((int) $order->id_currency);

        return array(
            'id_order' => (int) $order->id,
            'id_cart' => (int) $order->id_cart,
            'id_customer' => (int) $order->id_customer,
            'total' => (string) $order->total_paid,
            'currency' => \Validate::isLoadedObject($currency) ? (string) $currency->iso_code : '',
            'current_state' => (int) $order->current_state,
            'reference' => (string) $order->reference,
        );
    }

    public function orderHasBeenPaid($orderId)
    {
        $order = new \Order((int) $orderId);
        if (!\Validate::isLoadedObject($order)) {
            return false;
        }

        // Order::hasBeenPaid() checks the order-state history for any state whose
        // `paid` flag is set — the correct "already paid" signal for the
        // roll-back guard.
        return (bool) $order->hasBeenPaid();
    }

    public function setOrderState($orderId, $orderStateId)
    {
        $orderId = (int) $orderId;
        $orderStateId = (int) $orderStateId;
        if ($orderStateId <= 0) {
            return;
        }

        $order = new \Order($orderId);
        if (!\Validate::isLoadedObject($order)) {
            return;
        }

        // No-op if the order is already in the target state (avoids duplicate
        // history rows when the server re-delivers the same terminal event).
        if ((int) $order->getCurrentState() === $orderStateId) {
            return;
        }

        $history = new \OrderHistory();
        $history->id_order = $orderId;
        // 2-arg form: do not reuse an existing payment — a hosted-checkout order
        // created in the awaiting state has no OrderPayment yet, so the paid
        // transition records one. addWithemail($autodate = true): always date the
        // history row; the buyer email is driven by the target OrderState's
        // send_email flag, not an argument here.
        $history->changeIdOrderState($orderStateId, $order);
        $history->addWithemail(true);
    }

    public function orderStateId($actionKey)
    {
        $key = isset(self::STATE_KEYS[$actionKey]) ? self::STATE_KEYS[$actionKey] : 'PAYMOS_OS_PENDING';
        $stateId = (int) \Configuration::get($key);
        if ($stateId > 0) {
            return $stateId;
        }

        // Manual-review has no core fallback; default it to the awaiting state so
        // a held order never silently lands in an unknown state.
        if ($actionKey === 'manual_review') {
            return (int) \Configuration::get('PAYMOS_OS_PENDING');
        }

        return $stateId;
    }

    public function addOrderNote($orderId, $note)
    {
        $order = new \Order((int) $orderId);
        if (!\Validate::isLoadedObject($order)) {
            return;
        }

        $message = new \Message();
        $message->message = \Tools::substr((string) $note, 0, 1600);
        $message->id_order = (int) $order->id;
        $message->id_cart = (int) $order->id_cart;
        $message->id_customer = (int) $order->id_customer;
        $message->private = 1;
        $message->add();
    }

    public function log($message, array $context = array())
    {
        $line = '[Paymos] ' . (string) $message;
        if (count($context) > 0) {
            $line .= ' ' . json_encode($context);
        }

        // Severity 3 = error in PrestaShop's log levels.
        \PrestaShopLogger::addLog($line, 3, null, 'PaymosPrestaShop');
    }
}
