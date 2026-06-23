<?php

declare(strict_types=1);

use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Http\HttpResponse;
use Paymos\Http\MockTransport;
use PaymosPrestaShop\GatewayCheckout;
use PaymosPrestaShop\InMemoryInvoiceStore;

function test_prestashop_gateway_checkout_creates_invoice_and_stores_snapshot()
{
    $store = new InMemoryInvoiceStore();
    $adapter = new FakePrestaShopAdapter();
    $transport = new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'status' => 'created',
            'payment_url' => 'https://checkout.paymos.test/inv_123',
        )), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport, static function () {
        return 1709000000;
    });

    $result = (new GatewayCheckout($store, $adapter, static function () use ($client) {
        return $client;
    }))->start(42, prestashop_settings());

    assertSameValue('https://checkout.paymos.test/inv_123', $result['payment_url'], 'checkout must return Paymos payment URL.');
    assertSameValue(1, count($transport->requests()), 'new invoice should call Paymos API once.');

    $row = $store->findByOrderId(42);
    assertSameValue('inv_123', $row['paymos_invoice_id'], 'created Paymos invoice id must be stored.');
    assertSameValue('ps_42_0', $row['external_order_id'], 'first external order id must be deterministic.');
    assertSameValue('100.00', $row['amount'], 'order amount snapshot must be stored.');
    assertSameValue('USD', $row['currency'], 'order currency snapshot must be stored.');
    assertSameValue(24, (int) $row['id_cart'], 'cart id must be stored on the snapshot.');

    $payload = json_decode($transport->requests()[0]['body'], true);
    assertSameValue('prj_123', $payload['project_id'], 'Paymos create payload must include project id.');
    assertSameValue('ps_42_0', $payload['external_order_id'], 'Paymos create payload must use Merchant API external_order_id.');
    assertSameValue('77', $payload['client_id'], 'Paymos create payload must use native PrestaShop customer id when available.');
    assertSameValue(false, isset($payload['order']), 'Paymos create payload must not use webhook/read-model order object.');
    assertSameValue(false, isset($payload['webhook_url']), 'Paymos create payload must not carry a webhook_url.');
    assertSameValue(false, isset($payload['lifetime']), 'Paymos create payload must not carry a fake lifetime field.');
}

function test_prestashop_gateway_checkout_does_not_use_email_as_client_id()
{
    $store = new InMemoryInvoiceStore();
    $adapter = new FakePrestaShopAdapter();
    $adapter->orders[42] = prestashop_order(array('id_customer' => 0));
    $transport = new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'status' => 'created',
            'payment_url' => 'https://checkout.paymos.test/inv_123',
        )), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);

    (new GatewayCheckout($store, $adapter, static function () use ($client) {
        return $client;
    }))->start(42, prestashop_settings());

    $payload = json_decode($transport->requests()[0]['body'], true);
    assertSameValue(false, isset($payload['client_id']), 'guest checkout must not send a client_id (never an email).');
}

function test_prestashop_gateway_checkout_reuses_existing_invoice_when_snapshot_matches()
{
    $store = new InMemoryInvoiceStore();
    $store->save(prestashop_snapshot(array('paymos_invoice_id' => 'inv_existing', 'payment_url' => 'https://checkout.paymos.test/existing')));

    $transport = new MockTransport(array());
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);
    $adapter = new FakePrestaShopAdapter();

    $result = (new GatewayCheckout($store, $adapter, static function () use ($client) {
        return $client;
    }))->start(42, prestashop_settings());

    assertSameValue('https://checkout.paymos.test/existing', $result['payment_url'], 'matching existing invoice must be reused.');
    assertSameValue('1', $result['reused'], 'reuse must be flagged.');
    assertSameValue(0, count($transport->requests()), 'reused invoice must not call Paymos API.');
}

function test_prestashop_gateway_checkout_renews_invoice_when_amount_changes()
{
    $store = new InMemoryInvoiceStore();
    $store->save(prestashop_snapshot(array(
        'paymos_invoice_id' => 'inv_old',
        'amount' => '50.00',
        'payment_url' => 'https://checkout.paymos.test/old',
    )));
    $transport = new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_new',
            'status' => 'created',
            'payment_url' => 'https://checkout.paymos.test/new',
        )), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);
    $adapter = new FakePrestaShopAdapter();

    (new GatewayCheckout($store, $adapter, static function () use ($client) {
        return $client;
    }))->start(42, prestashop_settings());

    $row = $store->findByOrderId(42);
    assertSameValue('inv_new', $row['paymos_invoice_id'], 'amount change must create a fresh Paymos invoice.');
    assertSameValue('ps_42_1', $row['external_order_id'], 'renewed invoice must increment external order id.');
    assertSameValue(1, count($transport->requests()), 'renewed invoice must call Paymos API.');
}

function test_prestashop_gateway_checkout_throws_when_response_missing_payment_url()
{
    // The Merchant API response only ever carries invoice_id + payment_url; there
    // are no id/url/checkout_url aliases. A response without payment_url must fail
    // loudly (the controller then fails the stranded order) — never silently
    // proceed with an empty redirect.
    $store = new InMemoryInvoiceStore();
    $adapter = new FakePrestaShopAdapter();
    $transport = new MockTransport(array(
        new HttpResponse(200, json_encode(array('invoice_id' => 'inv_123', 'status' => 'created')), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);

    try {
        (new GatewayCheckout($store, $adapter, static function () use ($client) {
            return $client;
        }))->start(42, prestashop_settings());
    } catch (RuntimeException $e) {
        assertContainsValue('payment URL', $e->getMessage(), 'the error must name the missing payment URL.');

        return;
    }

    throw new RuntimeException('GatewayCheckout must throw when the create response has no payment_url.');
}
