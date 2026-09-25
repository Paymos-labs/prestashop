<?php

declare(strict_types=1);

namespace PaymosPrestaShop;

use Paymos\Client;
use Paymos\Exception\NotFoundException;
use Paymos\Plugin\InvoiceRenewal;
use Paymos\Plugin\InvoiceReplacement;
use Paymos\Plugin\InvoiceReplacementBlockedException;
use Paymos\Plugin\StatusMapper;

/**
 * Flow A — checkout → Paymos invoice. Called from the `validation` front
 * controller AFTER PrestaShop has already created the order (so there is a stable
 * id_order to key on). Builds the Merchant API payload, creates the invoice via
 * the SDK, stores a snapshot, and returns the hosted-checkout payment_url to
 * redirect to.
 *
 * external_order_id is deterministic and version-bumped: it is reused while the
 * stored amount/currency snapshot matches, and a fresh suffix is minted when the
 * order amount changed so a changed order never reuses an invoice for the wrong
 * amount. This is the client-side half of the server's external_order_id
 * idempotency.
 */
final class GatewayCheckout
{
    /** @var InvoiceStoreInterface */
    private $store;

    /** @var PrestaShopAdapterInterface */
    private $prestashop;

    /** @var callable|null */
    private $clientFactory;

    public function __construct(InvoiceStoreInterface $store, PrestaShopAdapterInterface $prestashop, ?callable $clientFactory = null)
    {
        $this->store = $store;
        $this->prestashop = $prestashop;
        $this->clientFactory = $clientFactory;
    }

    /**
     * Build the Paymos create-invoice payload. Carries ONLY the fields
     * CreateInvoiceRequest accepts — no webhook_url / success_url / cancel_url /
     * metadata / lifetime (the webhook destination is per-project in the
     * dashboard; TTL is server-side; the buyer return URL is the Paymos
     * payment_url). client_id is the native PrestaShop customer id (a guest
     * checkout still has a real customer id) — never an email; omitted only when
     * there is no customer id at all.
     *
     * @return array<string, string>
     */
    public function buildInvoicePayload($projectId, $amount, $currency, $externalOrderId, $clientId = '')
    {
        $payload = array(
            'project_id' => (string) $projectId,
            'amount' => (string) $amount,
            'currency' => strtoupper((string) $currency),
            'external_order_id' => (string) $externalOrderId,
            'allow_multiple_payments' => true,
        );

        $clientId = trim((string) $clientId);
        if ($clientId !== '' && $clientId !== '0') {
            $payload['client_id'] = $clientId;
        }

        return $payload;
    }

    /**
     * @param array<string, string> $settings
     * @return array<string, string>
     */
    public function start($orderId, array $settings)
    {
        $config = Config::fromSettings($settings);
        $order = $this->prestashop->getOrder($orderId);
        if (count($order) === 0) {
            throw new \RuntimeException('PrestaShop order was not found.');
        }

        $amount = $this->amount($this->field($order, 'total'));
        $currency = strtoupper($this->field($order, 'currency'));
        $cartId = (int) $this->field($order, 'id_cart');
        $existing = $this->store->findByOrderId($orderId);

        if (is_array($existing) && $this->snapshotMatches($existing, $amount, $currency, $config)
            && $this->keepsExistingInvoice($existing, $config)) {
            return array(
                'invoice_id' => (string) $existing['paymos_invoice_id'],
                'payment_url' => (string) $existing['payment_url'],
                'reused' => '1',
            );
        }

        if (is_array($existing)) {
            $this->closeBeforeReplacing($orderId, $existing, $config);
        }

        $renewCount = is_array($existing) && isset($existing['renew_count']) ? ((int) $existing['renew_count'] + 1) : 0;
        $externalOrderId = 'ps_' . (int) $orderId . '_' . $renewCount;
        $payload = $this->buildInvoicePayload(
            $config->projectId(),
            $amount,
            $currency,
            $externalOrderId,
            $this->clientId($order)
        );
        $response = $this->client($config)->invoices()->create($payload);

        // The Merchant API create response is an InvoiceStatusContract: the
        // invoice id is `invoice_id` and the hosted-checkout link is
        // `payment_url`. No other aliases exist server-side.
        $paymosInvoiceId = $this->responseField($response, array('invoice_id'));
        $paymentUrl = $this->responseField($response, array('payment_url'));
        if ($paymosInvoiceId === '' || $paymentUrl === '') {
            throw new \RuntimeException('Paymos invoice create response is missing invoice id or payment URL.');
        }

        $this->store->save(array(
            'id_order' => (int) $orderId,
            'id_cart' => $cartId,
            'paymos_invoice_id' => $paymosInvoiceId,
            'external_order_id' => $externalOrderId,
            'environment' => $config->environment(),
            'project_id' => $config->projectId(),
            'amount' => $amount,
            'currency' => $currency,
            'payment_url' => $paymentUrl,
            'status' => $this->responseField($response, array('status')) ?: 'created',
            'renew_count' => $renewCount,
        ));

        return array(
            'invoice_id' => $paymosInvoiceId,
            'payment_url' => $paymentUrl,
            'reused' => '0',
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function snapshotMatches(array $row, $amount, $currency, Config $config)
    {
        return (string) $row['amount'] === (string) $amount
            && strtoupper((string) $row['currency']) === strtoupper((string) $currency)
            && (string) $row['project_id'] === $config->projectId()
            && (string) $row['environment'] === $config->environment()
            && trim((string) $row['payment_url']) !== '';
    }

    /**
     * Whether the Paymos invoice behind a matching snapshot is still the one to
     * send the buyer to.
     *
     * A matching amount is not enough: the server answers a repeated
     * external_order_id with the same invoice whatever became of it, and a buyer
     * returning after it ended would land on an expired checkout. Its deadline is
     * the server's, not a copy kept here: confirming a network moves expires_at to
     * now + InvoiceOptions.PaymentTtl and sends no webhook. So: a row that already
     * ended unpaid is renewed at once (that final status came from the server and
     * never changes again); a paid one is kept (a second invoice would invite a
     * second payment); anything else is read back from the server (one GET) and
     * renewed only if the server says it ended unpaid or was never started before
     * its deadline (InvoiceRenewal). An invoice the server holds open — network
     * picked, funds confirming, part paid — is kept. When the server cannot be
     * reached the existing link is kept — the checkout it leads to is down just the
     * same. A 404 is not proof the invoice is gone (see closeBeforeReplacing), so
     * it goes on to the replacement, which refuses it.
     *
     * @param array<string, mixed> $row
     */
    private function keepsExistingInvoice(array $row, Config $config)
    {
        if (InvoiceRenewal::isRequired($row)) {
            return false;
        }
        if (StatusMapper::isFinalStatus(isset($row['status']) ? (string) $row['status'] : '')) {
            return true;
        }

        try {
            $invoice = $this->client($config)->invoices()->get((string) $row['paymos_invoice_id']);
        } catch (NotFoundException $e) {
            return false;
        } catch (\Exception $e) {
            return true;
        }

        if (!InvoiceRenewal::isRequired($invoice)) {
            return true;
        }

        $status = $this->responseField($invoice, array('status'));
        if ($status !== '') {
            $this->store->updateStatus((string) $row['paymos_invoice_id'], $status);
        }

        return false;
    }

    /**
     * The order's invoice is about to be replaced (the order changed, or the
     * invoice can no longer be paid). Cancel it on the server first, or the
     * buyer could pay both (BUG-166): the SDK cancels it, or confirms from the
     * server that it ended unpaid. Anything else — paid, still payable, 404,
     * no answer — keeps the old invoice, moves the order to the manual-review
     * state with a note, and stops the checkout.
     *
     * @param array<string, mixed> $row
     */
    private function closeBeforeReplacing($orderId, array $row, Config $config)
    {
        $environment = (string) $row['environment'];
        $recorded = isset($row['status']) ? (string) $row['status'] : '';
        $result = (new InvoiceReplacement(function () use ($config, $environment) {
            return $this->client($config, $environment);
        }))->close((string) $row['paymos_invoice_id'], $recorded);

        if ($result->isClosed()) {
            // Record the final status before the new row exists, so the old
            // invoice's own webhook (invoice.cancelled after our cancel) finds
            // a final row and is ignored as stale.
            if ($result->status() !== '' && $result->status() !== $recorded) {
                $this->store->updateStatus((string) $row['paymos_invoice_id'], $result->status());
            }

            return;
        }

        $this->prestashop->setOrderState((int) $orderId, $this->prestashop->orderStateId('manual_review'));
        $this->prestashop->addOrderNote((int) $orderId, 'Paymos payment needs manual review. ' . $result->summary());

        throw new InvoiceReplacementBlockedException($result);
    }

    /**
     * @param string|null $environment The environment the invoice lives in; the selected mode by default.
     */
    private function client(Config $config, $environment = null)
    {
        if ($this->clientFactory !== null) {
            return call_user_func($this->clientFactory, $config, $environment);
        }

        return new Client($environment === null
            ? $config->clientConfig()
            : $config->clientConfigForEnvironment($environment));
    }

    private function amount($amount)
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * @param array<string, mixed> $order
     */
    private function clientId(array $order)
    {
        $customerId = $this->field($order, 'id_customer');

        return $customerId !== '' && $customerId !== '0' ? $customerId : '';
    }

    /**
     * @param array<string, mixed> $source
     */
    private function field(array $source, $key)
    {
        return isset($source[$key]) && is_scalar($source[$key]) ? trim((string) $source[$key]) : '';
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, string> $path
     */
    private function responseField(array $source, array $path)
    {
        $current = $source;
        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return '';
            }
            $current = $current[$segment];
        }

        return is_scalar($current) ? (string) $current : '';
    }
}
