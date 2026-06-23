<?php

declare(strict_types=1);

namespace PaymosPrestaShop;

use Paymos\Client;

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

    public function __construct(InvoiceStoreInterface $store, PrestaShopAdapterInterface $prestashop, callable $clientFactory = null)
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

        if (is_array($existing) && $this->snapshotMatches($existing, $amount, $currency, $config)) {
            return array(
                'invoice_id' => (string) $existing['paymos_invoice_id'],
                'payment_url' => (string) $existing['payment_url'],
                'reused' => '1',
            );
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

    private function client(Config $config)
    {
        if ($this->clientFactory !== null) {
            return call_user_func($this->clientFactory, $config);
        }

        return new Client($config->clientConfig());
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
