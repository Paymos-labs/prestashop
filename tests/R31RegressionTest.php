<?php

declare(strict_types=1);

final class R31FailingInvoiceStore implements \PaymosPrestaShop\InvoiceStoreInterface
{
    public $inner;
    public $failSave;
    public $saves = 0;
    public function __construct(int $failSave) { $this->failSave = $failSave; $this->inner = new \PaymosPrestaShop\InMemoryInvoiceStore(); }
    public function save(array $row) {
        if (++$this->saves === $this->failSave) { throw new RuntimeException('Snapshot write failed.'); }
        $this->inner->save($row);
    }
    public function findByOrderId($id) { return $this->inner->findByOrderId($id); }
    public function findByExternalOrderId($id) { return $this->inner->findByExternalOrderId($id); }
    public function updateStatus($id, $status) { $this->inner->updateStatus($id, $status); }
    public function findUnpaidRecent($limit, $since) { return $this->inner->findUnpaidRecent($limit, $since); }
}

function r31_prestashop_expect_failure(callable $action, string $message): void
{
    try { $action(); } catch (RuntimeException $error) {
        assertContainsValue($message, $error->getMessage(), 'Expected the precise failure.'); return;
    }
    throw new LogicException('Expected exception: ' . $message);
}

function r31_prestashop_client(array $responses): array
{
    $transport = new \Paymos\Http\MockTransport(array_map(static function ($data) {
        return new \Paymos\Http\HttpResponse(200, json_encode($data), array());
    }, $responses));
    $client = new \Paymos\Client(new \Paymos\ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);
    return array($transport, static function () use ($client) { return $client; });
}

function test_prestashop_r31_failed_intent_save_prevents_http()
{
    $store = new R31FailingInvoiceStore(1);
    list($transport, $factory) = r31_prestashop_client(array());
    r31_prestashop_expect_failure(static function () use ($store, $factory) {
        (new \PaymosPrestaShop\GatewayCheckout($store, new FakePrestaShopAdapter(), $factory))->start(42, prestashop_settings());
    }, 'Snapshot write failed');
    assertSameValue(array(), $transport->requests(), 'No physical HTTP before the intent is durable.');
}

function test_prestashop_r31_failed_mapping_save_recovers_same_immutable_request()
{
    $store = new R31FailingInvoiceStore(2);
    $invoice = array('invoice_id' => 'inv_123', 'payment_url' => 'https://checkout.paymos.test/inv_123', 'status' => 'awaiting_client');
    list($transport, $factory) = r31_prestashop_client(array($invoice, $invoice, $invoice));
    $checkout = new \PaymosPrestaShop\GatewayCheckout($store, new FakePrestaShopAdapter(), $factory);
    r31_prestashop_expect_failure(static function () use ($checkout) { $checkout->start(42, prestashop_settings()); }, 'Snapshot write failed');
    $pending = $store->findByOrderId(42);
    assertSameValue('creating', $pending['status'], 'The original durable intent survives a failed final save.');
    assertSameValue('', \PaymosPrestaShop\PendingPage::decide(1, 8, 9, false, $pending)['resume_url'], 'Pending request JSON is never exposed as a link.');
    assertSameValue($transport->requests()[0]['body'], $pending['payment_url'], 'The exact request including client id is retained.');
    $result = $checkout->start(42, prestashop_settings());
    assertSameValue($invoice['payment_url'], $result['payment_url'], 'Redirect is returned only after final save.');
    assertSameValue($transport->requests()[0]['body'], $transport->requests()[1]['body'], 'Recovery reuses the exact body/idempotency key.');
    assertSameValue('ps_42_0', $store->findByOrderId(42)['external_order_id'], 'Recovery does not mint a generation.');
}

function test_prestashop_r31_callback_and_cron_recover_a_stranded_creation_intent()
{
    foreach (array('callback', 'cron') as $route) {
        $store = new R31FailingInvoiceStore(2);
        $adapter = new FakePrestaShopAdapter();
        $event = prestashop_invoice_event('evt_r31_' . $route, 'invoice.paid', 'paid');
        $invoice = $event['data'] + array('payment_url' => 'https://checkout.paymos.test/inv_123');
        list($transport, $factory) = r31_prestashop_client(array($invoice, $invoice, $invoice));
        r31_prestashop_expect_failure(static function () use ($store, $factory, $adapter) {
            (new \PaymosPrestaShop\GatewayCheckout($store, $adapter, $factory))->start(42, prestashop_settings());
        }, 'Snapshot write failed');
        if ($route === 'callback') {
            $body = json_encode($event);
            $processor = new \PaymosPrestaShop\CallbackProcessor($adapter, $store, new \PaymosPrestaShop\InMemoryEventStore(), $factory);
            assertSameValue(200, $processor->handle($body, prestashop_signed_header('whsec_sandbox', $body, time()), prestashop_settings())->statusCode(), 'Callback repairs mapping via API.');
        } else {
            assertSameValue(1, (new \PaymosPrestaShop\Reconciler($store, $adapter, $factory))->run(prestashop_settings()), 'Cron repairs mapping without buyer action.');
        }
        assertSameValue('paid', $store->findByOrderId(42)['status'], $route . ' records applied payment.');
        assertSameValue(1, count($adapter->transitions), $route . ' applies native order exactly once.');
        assertSameValue($transport->requests()[0]['body'], $transport->requests()[1]['body'], 'API recovery keeps original request.');
    }
}

function test_prestashop_r31_invoice_store_throws_on_false_database_writes()
{
    $db = new class implements \PaymosPrestaShop\DbInterface {
        public $existing;
        public function getRow($sql) { return $this->existing; }
        public function getRows($sql) { return array(); }
        public function getValue($sql) { return ''; }
        public function execute($sql) { return false; }
        public function insert($table, array $row) { return false; }
        public function escape($value) { return addslashes($value); }
    };
    $store = new \PaymosPrestaShop\InvoiceStore($db);
    r31_prestashop_expect_failure(static function () use ($store) { $store->save(prestashop_snapshot()); }, 'snapshot insert failed');
    $db->existing = prestashop_snapshot();
    r31_prestashop_expect_failure(static function () use ($store) { $store->save(prestashop_snapshot()); }, 'snapshot update failed');
    r31_prestashop_expect_failure(static function () use ($store) { $store->updateStatus('inv_123', 'paid'); }, 'status update failed');
}

final class R31NativeMappingDb implements \PaymosPrestaShop\DbInterface
{
    public $mappings = array();
    public $payments = array(array('id_order_payment' => 5, 'id_currency' => 1, 'amount' => '100'));
    public function execute($sql) { return true; }
    public function insert($table, array $row) { return true; }
    public function escape($value) { return addslashes($value); }
    public function getValue($sql) { return ''; }
    public function getRow($sql) { return array('iso_code' => 'USD', 'precision' => 2); }
    public function getRows($sql) {
        if (strpos($sql, '`paymos_invoice`') !== false) { return $this->mappings; }
        if (strpos($sql, '`order_payment`') !== false) { return $this->payments; }
        if (strpos($sql, '`order_detail`') !== false) { return array(array('product_quantity_refunded' => 0,
            'product_quantity_return' => 0, 'product_quantity_reinjected' => 0)); }
        if (strpos($sql, '`order_slip`') !== false) { return array(); }
        if (strpos($sql, 'SELECT id_order ') === 0) { return array(array('id_order' => 42)); }
        if (strpos($sql, '`orders`') !== false) { return array(array('id_order' => 42, 'module' => 'paymos', 'reference' => 'ORDER42',
            'id_currency' => 1, 'id_customer' => 2, 'id_shop' => 1, 'total_paid_tax_incl' => '100',
            'total_shipping_tax_incl' => '0', 'total_discounts_tax_incl' => '0')); }
        throw new RuntimeException('Unexpected SQL ' . $sql);
    }
}

function test_prestashop_r31_refund_uses_single_paid_generation_and_rejects_real_ambiguity()
{
    $db = new R31NativeMappingDb();
    $closed = prestashop_snapshot(array('status' => 'expired', 'paymos_invoice_id' => 'inv_old'));
    $paid = prestashop_snapshot(array('status' => 'paid', 'external_order_id' => 'ps_42_1', 'paymos_invoice_id' => 'inv_paid', 'renew_count' => 1));
    $db->mappings = array($closed, $paid);
    $native = new \PaymosPrestaShop\RefundNativePlatform(new \PaymosPrestaShop\RefundIntentStore($db));
    $snapshot = $native->snapshot(42);
    assertSameValue('inv_paid', $snapshot['invoice_id'], 'Expired history does not obscure the applied paid invoice.');
    assertSameValue('5', $snapshot['payment_id'], 'The unique native payment confirms the binding.');
    foreach (array('paid', 'paid_over', 'confirming', 'creating') as $ambiguous) {
        $db->mappings[0]['status'] = $ambiguous;
        r31_prestashop_expect_failure(static function () use ($native) { $native->snapshot(42); }, 'mapping_ambiguous');
    }
    $db->mappings = array($closed, $paid);
    $db->payments[] = $db->payments[0];
    r31_prestashop_expect_failure(static function () use ($native) { $native->snapshot(42); }, 'native_payment_not_full');
    $db->payments = array(array('id_order_payment' => 5, 'id_currency' => 1, 'amount' => '99'));
    r31_prestashop_expect_failure(static function () use ($native) { $native->snapshot(42); }, 'native_payment_not_full');
}
