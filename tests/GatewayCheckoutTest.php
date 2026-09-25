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

    $transport = new MockTransport(array(
        prestashop_live_invoice_response('inv_existing', 'awaiting_client', time() + 600),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);
    $adapter = new FakePrestaShopAdapter();

    $result = (new GatewayCheckout($store, $adapter, static function () use ($client) {
        return $client;
    }))->start(42, prestashop_settings());

    assertSameValue('https://checkout.paymos.test/existing', $result['payment_url'], 'matching existing invoice must be reused.');
    assertSameValue('1', $result['reused'], 'reuse must be flagged.');
    assertSameValue(1, count($transport->requests()), 'a reused invoice is checked against the server once.');
    assertSameValue('GET', $transport->requests()[0]['method'], 'the check is a read, never a second create.');
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

function prestashop_live_invoice_response($invoiceId, $status, $expiresAt)
{
    return new HttpResponse(200, json_encode(array(
        'invoice_id' => $invoiceId,
        'project_id' => 'prj_123',
        'status' => $status,
        'payment_url' => 'https://checkout.paymos.test/' . $invoiceId,
        'expires_at' => $expiresAt,
        'order' => array('external_id' => 'ps_42_0', 'amount' => '100', 'currency' => 'USD'),
    )), array());
}

function test_prestashop_gateway_checkout_renews_an_invoice_that_expired_on_the_server()
{
    // BUG-090 (PrestaShop): same amount and currency, but the Paymos invoice's
    // 30 minutes ran out — the old link leads to an expired checkout.
    $store = new InMemoryInvoiceStore();
    $store->save(prestashop_snapshot(array('paymos_invoice_id' => 'inv_existing', 'payment_url' => 'https://checkout.paymos.test/existing', 'status' => 'awaiting_client')));
    $transport = new MockTransport(array(
        prestashop_live_invoice_response('inv_existing', 'awaiting_client', time() - 3600),
        new HttpResponse(201, json_encode(array(
            'invoice_id' => 'inv_fresh',
            'status' => 'awaiting_client',
            'payment_url' => 'https://checkout.paymos.test/fresh',
        )), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);

    $result = (new GatewayCheckout($store, new FakePrestaShopAdapter(), static function () use ($client) {
        return $client;
    }))->start(42, prestashop_settings());

    assertSameValue('https://checkout.paymos.test/fresh', $result['payment_url'], 'an expired invoice must be replaced by a fresh one.');
    assertSameValue('ps_42_1', $store->findByOrderId(42)['external_order_id'], 'the fresh invoice needs a new external order id.');
}

function test_prestashop_gateway_checkout_renews_without_a_lookup_when_the_invoice_is_already_final()
{
    $store = new InMemoryInvoiceStore();
    $store->save(prestashop_snapshot(array('paymos_invoice_id' => 'inv_existing', 'payment_url' => 'https://checkout.paymos.test/existing', 'status' => 'expired')));
    $transport = new MockTransport(array(
        new HttpResponse(201, json_encode(array(
            'invoice_id' => 'inv_fresh',
            'status' => 'awaiting_client',
            'payment_url' => 'https://checkout.paymos.test/fresh',
        )), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);

    $result = (new GatewayCheckout($store, new FakePrestaShopAdapter(), static function () use ($client) {
        return $client;
    }))->start(42, prestashop_settings());

    assertSameValue('https://checkout.paymos.test/fresh', $result['payment_url'], 'a final invoice must be replaced by a fresh one.');
    assertSameValue(1, count($transport->requests()), 'a recorded final status needs no lookup, only the create.');
}

function test_prestashop_gateway_checkout_keeps_an_invoice_the_server_holds_open_past_the_old_deadline()
{
    // BUG-163: confirming a network moves expires_at on the server and sends
    // no webhook. Only the server's answer decides; an open invoice is kept.
    foreach (array('awaiting_payment', 'confirming', 'underpaid_waiting') as $status) {
        $store = new InMemoryInvoiceStore();
        $store->save(prestashop_snapshot(array('paymos_invoice_id' => 'inv_existing', 'payment_url' => 'https://checkout.paymos.test/existing', 'status' => 'awaiting_client')));
        $transport = new MockTransport(array(
            prestashop_live_invoice_response('inv_existing', $status, time() - 3600),
        ));
        $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);

        $result = (new GatewayCheckout($store, new FakePrestaShopAdapter(), static function () use ($client) {
            return $client;
        }))->start(42, prestashop_settings());

        assertSameValue('https://checkout.paymos.test/existing', $result['payment_url'], $status . ': the open invoice keeps its link.');
        assertSameValue(1, count($transport->requests()), $status . ': one lookup and no new invoice.');
        assertSameValue('ps_42_0', $store->findByOrderId(42)['external_order_id'], $status . ': the external order id is not bumped.');
    }
}
