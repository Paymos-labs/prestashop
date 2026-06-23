<?php

declare(strict_types=1);

namespace PaymosPrestaShop;

use Paymos\Client;
use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\StatusMapper;

/**
 * Safety net for a webhook the server retried into a downed shop. Selects orders
 * still awaiting Paymos payment past a grace window, pulls each invoice from the
 * API, and funnels the result through the SAME OrderMapper (via
 * CallbackProcessor::applyTrustedInvoice) so the roll-back guard and AmountGuard
 * still apply. Throttled (50 rows / 24h window) and skips terminal orders.
 */
final class Reconciler
{
    /** @var InvoiceStoreInterface */
    private $store;

    /** @var PrestaShopAdapterInterface */
    private $prestashop;

    /** @var callable|null */
    private $clientFactory;

    public function __construct(InvoiceStoreInterface $store, PrestaShopAdapterInterface $prestashop, callable $clientFactory = null)
    {
        $this->store = $store;
        $this->prestashop = $prestashop;
        $this->clientFactory = $clientFactory;
    }

    /**
     * @param array<string, string> $settings
     */
    public function run(array $settings, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $count = 0;

        foreach ($this->store->findUnpaidRecent(50, $now - 86400) as $row) {
            try {
                $invoice = $this->client((string) $row['environment'], $settings)
                    ->invoices()->get((string) $row['paymos_invoice_id']);

                if (!$this->snapshotMatches($row, $invoice)) {
                    $this->prestashop->log('Paymos reconcile skipped invoice snapshot mismatch.', array(
                        'invoice' => $invoice,
                        'row' => $row,
                    ));
                    continue;
                }

                $applied = (new CallbackProcessor($this->prestashop, $this->store, new InMemoryEventStore(), $this->clientFactory))
                    ->applyTrustedInvoice($invoice, $row, $now);
                if ($applied) {
                    $count++;
                }
            } catch (\Exception $e) {
                $this->prestashop->log('Paymos reconcile failed.', array(
                    'error' => $e->getMessage(),
                    'row' => $row,
                ));
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $invoice
     */
    private function snapshotMatches(array $row, array $invoice)
    {
        return $this->matches((string) $row['project_id'], $this->field($invoice, array('project_id')))
            && $this->matches((string) $row['external_order_id'], $this->field($invoice, array('order', 'external_id')))
            && $this->amountMatches((string) $row['amount'], $this->field($invoice, array('order', 'amount')))
            && $this->matches(strtoupper((string) $row['currency']), strtoupper($this->field($invoice, array('order', 'currency'))))
            && StatusMapper::invoiceAction('', $this->field($invoice, array('status'))) !== StatusMapper::ACTION_IGNORE;
    }

    private function matches($expected, $actual)
    {
        $expected = trim((string) $expected);
        $actual = trim((string) $actual);

        return $expected === '' || $actual === '' || $expected === $actual;
    }

    /**
     * Decimal-safe amount comparison. The server trims trailing zeros on the
     * wire ("100.00" -> "100"), so a raw string compare would reject most paid
     * invoices — exactly as reverse-verify already handles it via AmountGuard.
     */
    private function amountMatches($expected, $actual)
    {
        $expected = trim((string) $expected);
        $actual = trim((string) $actual);

        return $expected === '' || $actual === '' || AmountGuard::amountsEqual($expected, $actual);
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
}
