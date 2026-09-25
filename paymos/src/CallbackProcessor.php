<?php

declare(strict_types=1);

namespace PaymosPrestaShop;

use Paymos\Client;
use Paymos\Exception\DuplicateEventException;
use Paymos\Exception\EventInProgressException;
use Paymos\Exception\SignatureMismatchException;
use Paymos\Exception\TimestampSkewException;
use Paymos\Plugin\InvoiceReverseVerifier;
use Paymos\Plugin\StatusMapper;
use Paymos\Webhook\EventStoreInterface;
use Paymos\Webhook\MultiEnvironmentWebhookVerifier;
use Paymos\Webhook\WebhookEvent;

/**
 * Flow B — verified webhook → order update. Processing order:
 *   1. MultiEnvironmentWebhookVerifier::process — verify HMAC across both
 *      environment secrets, reject a multi-environment match, and dedup, in one
 *      call.
 *   2. Filter non-invoice events → commit + 200 "ignored".
 *   3. Assert payload environment (is_test ↔ matched environment).
 *   4. Resolve the order by stored external_order_id; assert its
 *      environment/project/invoice id match the event.
 *   5. Reverse-verify TERMINAL events (paid/paid_over/underpaid/expired/
 *      cancelled): pull the live invoice from the API and re-check. Mismatch →
 *      throw → 400 → order untouched → server retries.
 *   6. OrderMapper mutates the order (action map + roll-back guard + AmountGuard).
 *   7. Transactional dedup: commit() only after the order mutation succeeds;
 *      release() on failure so the server retries.
 *
 * HTTP contract the server's retry logic depends on: duplicate → 200, signature
 * mismatch → 401, timestamp skew → 401, config error → 500, any other failure →
 * 400. There is no 202.
 */
final class CallbackProcessor
{
    /** @var PrestaShopAdapterInterface */
    private $prestashop;

    /** @var InvoiceStoreInterface */
    private $invoiceStore;

    /** @var EventStoreInterface */
    private $eventStore;

    /** @var callable|null */
    private $clientFactory;

    public function __construct(
        PrestaShopAdapterInterface $prestashop,
        InvoiceStoreInterface $invoiceStore,
        EventStoreInterface $eventStore,
        ?callable $clientFactory = null
    ) {
        $this->prestashop = $prestashop;
        $this->invoiceStore = $invoiceStore;
        $this->eventStore = $eventStore;
        $this->clientFactory = $clientFactory;
    }

    /**
     * @param array<string, string> $settings
     */
    public function handle($rawBody, $signatureHeader, array $settings, $now = null)
    {
        try {
            $config = Config::fromSettings($settings);
            $verified = (new MultiEnvironmentWebhookVerifier($config->webhookSecrets(), $this->eventStore))
                ->process($signatureHeader, $rawBody, $now);
            $environment = $verified->environment();
            $event = $verified->event();

            if (!$event->isInvoiceEvent()) {
                $this->commitEvent();

                return new CallbackResult(200, 'OK');
            }

            $this->assertPayloadEnvironment($event, $environment);
            $this->applyVerifiedEvent($event, $environment, $settings, true);
            $this->commitEvent();

            return new CallbackResult(200, 'OK');
        } catch (DuplicateEventException $e) {
            $this->prestashop->log('Paymos duplicate webhook ignored.', array('duplicate' => true));

            return new CallbackResult(200, 'OK', true);
        } catch (EventInProgressException $e) {
            // Another delivery of this event holds the lock and has not finished.
            // Not a duplicate: a 2xx would mark it delivered even if that delivery
            // then fails. 409 makes the server retry; the lock is not ours to drop.
            $this->prestashop->log('Paymos webhook is still being processed by another delivery.', array('in_progress' => true));

            return new CallbackResult(409, 'In progress');
        } catch (SignatureMismatchException $e) {
            return new CallbackResult(401, 'Bad signature');
        } catch (TimestampSkewException $e) {
            return new CallbackResult(401, 'Bad timestamp');
        } catch (\InvalidArgumentException $e) {
            $this->releaseEvent();
            $this->prestashop->log('Paymos PrestaShop configuration error.', array('error' => $e->getMessage()));

            return new CallbackResult(500, 'Configuration error');
        } catch (\RuntimeException $e) {
            $this->releaseEvent();
            $this->prestashop->log('Paymos webhook processing failed.', array('error' => $e->getMessage()));

            return new CallbackResult(400, 'Processing failed');
        }
    }

    /**
     * Apply a fully-trusted invoice (reconcile path). The invoice was fetched
     * from the API directly, so it is wrapped in a synthetic event and applied
     * WITHOUT a second reverse-verify (it would just re-fetch the same object).
     *
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $row
     * @return bool
     */
    public function applyTrustedInvoice(array $invoice, array $row, $now)
    {
        $invoiceId = $this->field($invoice, array('invoice_id'));
        $status = $this->field($invoice, array('status'));
        $event = new WebhookEvent(array(
            'event_id' => 'reconcile_' . $invoiceId . '_' . $status,
            'event_type' => $this->eventTypeForStatus($status),
            'occurred_at' => (int) $now,
            'data' => $invoice,
        ));

        return $this->applyEventToOrder($event, (string) $row['environment'], $row, false);
    }

    /**
     * @param array<string, string> $settings
     */
    private function applyVerifiedEvent(WebhookEvent $event, $environment, array $settings, $reverseVerify)
    {
        $externalOrderId = $event->externalOrderId();
        if ($externalOrderId === '') {
            throw new \RuntimeException('Paymos webhook payload is missing external order id.');
        }

        $row = $this->invoiceStore->findByExternalOrderId($externalOrderId);
        if (!is_array($row)) {
            throw new \RuntimeException('Paymos PrestaShop invoice snapshot was not found.');
        }

        $this->applyEventToOrder($event, $environment, $row, $reverseVerify, $settings);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $settings
     */
    private function applyEventToOrder(WebhookEvent $event, $environment, array $row, $reverseVerify, array $settings = array())
    {
        $this->assertRowMatchesEvent($row, $event, $environment);

        if ($reverseVerify && $this->requiresReverseVerify($event)) {
            $result = (new InvoiceReverseVerifier($this->client($environment, $settings)))->verify($event, array(
                'project_id' => (string) $row['project_id'],
                'external_order_id' => (string) $row['external_order_id'],
                'amount' => (string) $row['amount'],
                'currency' => (string) $row['currency'],
            ));

            if (!$result->isVerified()) {
                throw new \RuntimeException('Paymos reverse verification failed: ' . $result->reason());
            }
        }

        // Nothing leaves a final status on the server (Invoice.IsTerminal), so an
        // event that arrives after one is an out-of-order redelivery. The mapper's
        // paid guard only protects paid orders; a failed or cancelled one was
        // moved back by a stale underpaid_waiting or confirming. The recorded
        // invoice status decides, and it stays final.
        if (StatusMapper::isFinalStatus(isset($row['status']) ? (string) $row['status'] : '')) {
            $this->prestashop->log('Paymos ignored an invoice status that arrived after a final one.', array(
                'invoice' => $event->invoiceId(),
                'final_status' => (string) $row['status'],
                'event_type' => $event->type(),
            ));
            return false;
        }

        $this->invoiceStore->updateStatus($event->invoiceId(), $event->status());

        return (new OrderMapper($this->prestashop))->apply($event, $row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function assertRowMatchesEvent(array $row, WebhookEvent $event, $environment)
    {
        if ((string) $row['environment'] !== (string) $environment) {
            throw new \RuntimeException('Paymos event environment does not match PrestaShop invoice snapshot.');
        }
        if ((string) $row['project_id'] !== '' && $event->projectId() !== '' && (string) $row['project_id'] !== $event->projectId()) {
            throw new \RuntimeException('Paymos event project does not match PrestaShop invoice snapshot.');
        }
        if ((string) $row['external_order_id'] !== '' && $event->externalOrderId() !== '' && (string) $row['external_order_id'] !== $event->externalOrderId()) {
            throw new \RuntimeException('Paymos event external order does not match PrestaShop invoice snapshot.');
        }
        if ((string) $row['paymos_invoice_id'] !== '' && $event->invoiceId() !== '' && (string) $row['paymos_invoice_id'] !== $event->invoiceId()) {
            throw new \RuntimeException('Paymos event invoice id does not match PrestaShop invoice snapshot.');
        }
    }

    private function assertPayloadEnvironment(WebhookEvent $event, $environment)
    {
        $isTest = $event->isTest();
        if ($isTest === null) {
            return;
        }

        if ($environment === 'sandbox' && $isTest !== true) {
            throw new \RuntimeException('Sandbox webhook payload is not marked as test.');
        }
        if ($environment === 'live' && $isTest !== false) {
            throw new \RuntimeException('Live webhook payload is marked as test.');
        }
    }

    private function requiresReverseVerify(WebhookEvent $event)
    {
        $action = StatusMapper::invoiceAction($event->type(), $event->status());

        return in_array($action, array(
            StatusMapper::ACTION_PAYMENT_COMPLETE,
            StatusMapper::ACTION_FAIL_ORDER,
            StatusMapper::ACTION_CANCEL_ORDER,
        ), true);
    }

    /**
     * @param array<string, string> $settings
     */
    private function client($environment, array $settings)
    {
        if ($this->clientFactory !== null) {
            return call_user_func($this->clientFactory, $environment);
        }

        return new Client(Config::fromSettings($settings)->clientConfigForEnvironment($environment));
    }

    private function eventTypeForStatus($status)
    {
        switch (StatusMapper::invoiceAction('', $status)) {
            case StatusMapper::ACTION_CONFIRMING:
                return 'invoice.confirming';
            case StatusMapper::ACTION_AWAITING_PAYMENT:
                return 'invoice.underpaid_waiting';
            case StatusMapper::ACTION_PAYMENT_COMPLETE:
                return ((string) $status === 'paid_over') ? 'invoice.paid_over' : 'invoice.paid';
            case StatusMapper::ACTION_FAIL_ORDER:
                return 'invoice.underpaid';
            case StatusMapper::ACTION_CANCEL_ORDER:
                return ((string) $status === 'expired') ? 'invoice.expired' : 'invoice.cancelled';
        }

        // Unknown status: leave the synthetic event type empty. StatusMapper's
        // status fallback still classifies it correctly, and an unmapped status
        // resolves to ACTION_IGNORE — never a fabricated, non-existent event type.
        return '';
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $path
     */
    private function field(array $payload, array $path)
    {
        $current = $payload;
        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return '';
            }
            $current = $current[$segment];
        }

        return is_scalar($current) ? (string) $current : '';
    }

    private function commitEvent()
    {
        if (method_exists($this->eventStore, 'commit')) {
            $this->eventStore->commit();
        }
    }

    private function releaseEvent()
    {
        if (method_exists($this->eventStore, 'release')) {
            $this->eventStore->release();
        }
    }
}
