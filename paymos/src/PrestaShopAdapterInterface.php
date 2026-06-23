<?php

declare(strict_types=1);

namespace PaymosPrestaShop;

/**
 * Abstraction over the PrestaShop objects the checkout/callback flows mutate
 * (Order, OrderState, OrderHistory, Configuration, logging). The production
 * adapter wraps the real PrestaShop classes; tests inject a fake. This is what
 * keeps GatewayCheckout / CallbackProcessor / OrderMapper unit-testable without
 * a running PrestaShop.
 */
interface PrestaShopAdapterInterface
{
    /**
     * Snapshot of an order: id_order, id_cart, id_customer, total, currency,
     * current_state, reference. Returns an empty array when the order is missing.
     *
     * @return array<string, mixed>
     */
    public function getOrder($orderId);

    /**
     * Whether the order is already in a "paid" order state (PrestaShop
     * Order::hasBeenPaid / OrderState `paid` flag). Used by the roll-back guard.
     */
    public function orderHasBeenPaid($orderId);

    /**
     * Transition the order to a new order state and append a history row.
     * Mirrors PrestaShop's OrderHistory::changeIdOrderState + addWithemail.
     * Whether the buyer is emailed is governed by the target OrderState's
     * `send_email` flag, not by the caller.
     */
    public function setOrderState($orderId, $orderStateId);

    /**
     * Configured order-state id for a logical Paymos action key
     * (pending|confirming|paid|failed|cancelled|manual_review). Falls back to a
     * sane PrestaShop core state when unset.
     */
    public function orderStateId($actionKey);

    /**
     * Append a private note on the order (manual-review / mismatch trail).
     */
    public function addOrderNote($orderId, $note);

    public function log($message, array $context = array());
}
