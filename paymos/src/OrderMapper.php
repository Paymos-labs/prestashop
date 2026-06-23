<?php

declare(strict_types=1);

namespace PaymosPrestaShop;

use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\StatusMapper;
use Paymos\Webhook\WebhookEvent;

/**
 * Translates a verified Paymos invoice event into a PrestaShop order-state
 * transition. Owns three safety rules the canon mandates:
 *
 *   1. State is decided ONLY via StatusMapper::invoiceAction — never by
 *      hardcoding status strings. Unknown events fall through to IGNORE (no
 *      transition), never to paid.
 *   2. Roll-back guard: a late `cancelled`/`expired`/`confirming` after the order
 *      is already paid must never downgrade it. Out-of-order webhook delivery is
 *      expected.
 *   3. On PAYMENT_COMPLETE, AmountGuard triangulates the invoice snapshot, the
 *      current order total, and the event order amount. A mismatch is held for
 *      manual review (NOT marked paid).
 */
final class OrderMapper
{
    /** @var PrestaShopAdapterInterface */
    private $prestashop;

    public function __construct(PrestaShopAdapterInterface $prestashop)
    {
        $this->prestashop = $prestashop;
    }

    /**
     * @param array<string, mixed> $row Stored invoice snapshot.
     * @return bool True when the order was transitioned to paid.
     */
    public function apply(WebhookEvent $event, array $row)
    {
        $action = StatusMapper::invoiceAction($event->type(), $event->status());
        if ($action === StatusMapper::ACTION_IGNORE) {
            return false;
        }

        $orderId = (int) $row['id_order'];
        $order = $this->prestashop->getOrder($orderId);
        if (count($order) === 0) {
            throw new \RuntimeException('PrestaShop order for Paymos invoice snapshot was not found.');
        }

        // Roll-back guard: never downgrade an order that is already paid.
        if ($this->prestashop->orderHasBeenPaid($orderId) && $this->wouldDowngradePaidOrder($action)) {
            $this->prestashop->addOrderNote(
                $orderId,
                'Paymos: ignored out-of-order "' . $event->type() . '" event for an already-paid order. Invoice: ' . $event->invoiceId()
            );

            return false;
        }

        if ($action === StatusMapper::ACTION_PAYMENT_COMPLETE) {
            $currentAmount = $this->formatAmount($this->scalar($order, 'total', $row['amount']));
            $currentCurrency = strtoupper($this->scalar($order, 'currency', $row['currency']));

            if (!AmountGuard::isSafeToComplete(
                $row['amount'],
                $row['currency'],
                $currentAmount,
                $currentCurrency,
                $event->orderAmount(),
                $event->orderCurrency()
            )) {
                $this->prestashop->addOrderNote(
                    $orderId,
                    AmountGuard::mismatchSummary(
                        $row['amount'],
                        $row['currency'],
                        $currentAmount,
                        $currentCurrency,
                        $event->orderAmount(),
                        $event->orderCurrency()
                    )
                );
                $this->prestashop->setOrderState($orderId, $this->prestashop->orderStateId('manual_review'));

                return false;
            }
        }

        $stateId = $this->stateIdForAction($action);
        $this->prestashop->setOrderState($orderId, $stateId);

        // Record the on-chain transaction reference on the paid order so the
        // merchant has the tx hash + explorer link in the order's notes (the
        // siblings surface the same). Sandbox/simulated payments carry no
        // transfers, so this is a no-op there.
        if ($action === StatusMapper::ACTION_PAYMENT_COMPLETE) {
            $transfer = $this->selectedTransfer($event);
            if ($transfer['tx_hash'] !== '') {
                $note = 'Paymos payment confirmed. Transaction: ' . $transfer['tx_hash'];
                if ($transfer['explorer_url'] !== '') {
                    $note .= ' (' . $transfer['explorer_url'] . ')';
                }
                $this->prestashop->addOrderNote($orderId, $note);
            }
        }

        return $action === StatusMapper::ACTION_PAYMENT_COMPLETE;
    }

    /**
     * Latest confirmed on-chain transfer (tx_hash + explorer_url) from
     * data.payment.transfers[]; empty strings when the payload carries none.
     *
     * @return array{tx_hash: string, explorer_url: string}
     */
    private function selectedTransfer(WebhookEvent $event)
    {
        $payload = $event->toArray();
        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : array();
        $transfers = null;
        if (isset($data['payment']['transfers']) && is_array($data['payment']['transfers'])) {
            $transfers = $data['payment']['transfers'];
        } elseif (isset($data['transfers']) && is_array($data['transfers'])) {
            $transfers = $data['transfers'];
        }

        $confirmed = null;
        $latest = null;
        if ($transfers !== null) {
            foreach ($transfers as $transfer) {
                if (!is_array($transfer) || !isset($transfer['tx_hash']) || !is_string($transfer['tx_hash']) || $transfer['tx_hash'] === '') {
                    continue;
                }
                $latest = $transfer;
                $status = isset($transfer['status']) && is_string($transfer['status']) ? strtolower($transfer['status']) : '';
                if ($status === 'confirmed') {
                    $confirmed = $transfer;
                }
            }
        }

        $chosen = $confirmed !== null ? $confirmed : $latest;
        if ($chosen === null) {
            return array('tx_hash' => '', 'explorer_url' => '');
        }

        return array(
            'tx_hash' => (string) $chosen['tx_hash'],
            'explorer_url' => isset($chosen['explorer_url']) && is_string($chosen['explorer_url']) ? $chosen['explorer_url'] : '',
        );
    }

    private function wouldDowngradePaidOrder($action)
    {
        return in_array($action, array(
            StatusMapper::ACTION_CANCEL_ORDER,
            StatusMapper::ACTION_FAIL_ORDER,
            StatusMapper::ACTION_CONFIRMING,
            StatusMapper::ACTION_AWAITING_PAYMENT,
        ), true);
    }

    private function stateIdForAction($action)
    {
        switch ($action) {
            case StatusMapper::ACTION_PAYMENT_COMPLETE:
                return $this->prestashop->orderStateId('paid');
            case StatusMapper::ACTION_CONFIRMING:
                return $this->prestashop->orderStateId('confirming');
            case StatusMapper::ACTION_FAIL_ORDER:
                return $this->prestashop->orderStateId('failed');
            case StatusMapper::ACTION_CANCEL_ORDER:
                return $this->prestashop->orderStateId('cancelled');
            case StatusMapper::ACTION_AWAITING_PAYMENT:
            default:
                return $this->prestashop->orderStateId('pending');
        }
    }

    /**
     * @param array<string, mixed> $source
     */
    private function scalar(array $source, $key, $fallback)
    {
        return isset($source[$key]) && is_scalar($source[$key]) && trim((string) $source[$key]) !== ''
            ? trim((string) $source[$key])
            : (string) $fallback;
    }

    private function formatAmount($value)
    {
        return number_format((float) $value, 2, '.', '');
    }
}
