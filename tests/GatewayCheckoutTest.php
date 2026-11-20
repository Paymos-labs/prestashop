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
    assertSameValue('100', $row['amount'], 'exact normalized order amount snapshot must be stored.');
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

function test_prestashop_gateway_recovered_paid_create_is_applied_by_callback_or_reconcile()
{
    foreach (array('paid', 'paid_over') as $status) {
        foreach (array('callback', 'reconcile') as $route) {
            $store = new InMemoryInvoiceStore();
            $adapter = new FakePrestaShopAdapter();
            $invoice = array('invoice_id' => 'inv_123', 'project_id' => 'prj_123',
                'payment_url' => 'https://checkout.paymos.test/inv_123', 'status' => $status, 'is_test' => true,
                'order' => array('external_id' => 'ps_42_0', 'amount' => '100', 'currency' => 'USD'));
            $clientFactory = static function () use ($invoice) {
                return new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'),
                    new MockTransport(array(new HttpResponse(200, json_encode($invoice), array()))));
            };
            (new GatewayCheckout($store, $adapter, $clientFactory))->start(42, prestashop_settings());
            assertSameValue('created', $store->findByOrderId(42)['status'], 'Recovered remote terminal status is not a native receipt.');
            assertSameValue(0, count($adapter->transitions), 'Checkout does not assert native payment.');
            if ($route === 'callback') {
                $processor = new PaymosPrestaShop\CallbackProcessor($adapter, $store, new PaymosPrestaShop\InMemoryEventStore(), $clientFactory);
                $body = json_encode(prestashop_invoice_event('evt_recovered', 'invoice.' . $status, $status));
                $sig = prestashop_signed_header('whsec_sandbox', $body, time());
                assertSameValue(200, $processor->handle($body, $sig, prestashop_settings())->statusCode(), 'Recovered invoice callback applies.');
                assertSameValue(200, $processor->handle($body, $sig, prestashop_settings())->statusCode(), 'Recovered duplicate is acknowledged.');
            } else {
                $reconciler = new PaymosPrestaShop\Reconciler($store, $adapter, $clientFactory);
                assertSameValue(1, $reconciler->run(prestashop_settings(), time()), 'Recovered invoice remains in the reconciliation window.');
                assertSameValue(0, $reconciler->run(prestashop_settings(), time()), 'Applied terminal snapshot leaves the window.');
            }
            assertSameValue($status, $store->findByOrderId(42)['status'], 'Only successful native apply finalizes the snapshot.');
            assertSameValue(1, count($adapter->transitions), 'Recovered payment transitions once.');
            assertSameValue(5, $adapter->orders[42]['current_state'], 'Recovered payment is paid in PrestaShop.');
        }
    }
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
        prestashop_live_invoice_response('inv_old', 'cancelled', time() + 600),
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
    // BUG-166: the old invoice is cancelled on the server first.
    $requests = $transport->requests();
    assertSameValue(2, count($requests), 'the old invoice is cancelled, then the new one created.');
    assertSameValue('https://api.paymos.test/v1/invoices/inv_old/cancel', $requests[0]['url'], 'the old invoice is cancelled first.');
    assertSameValue('https://api.paymos.test/v1/invoices', $requests[1]['url'], 'the new invoice is created after the cancel.');
    assertSameValue('cancelled', $store->findByExternalOrderId('ps_42_0')['status'], 'the old row records the cancel.');
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
        'is_test' => true,
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
        prestashop_live_invoice_response('inv_existing', 'cancelled', time() - 3600),
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
    assertSameValue(array('GET', 'POST', 'POST'), array_column($transport->requests(), 'method'), 'read, cancel, create.');
    assertSameValue('https://api.paymos.test/v1/invoices/inv_existing/cancel', $transport->requests()[1]['url'], 'the stale invoice is cancelled before the replacement.');
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

function prestashop_problem($status, $code, $detail)
{
    return new HttpResponse($status, json_encode(array(
        'type' => 'https://paymos.io/errors/' . $code,
        'title' => 'Error',
        'status' => $status,
        'detail' => $detail,
        'code' => $code,
    )), array('Content-Type' => 'application/problem+json'));
}

function prestashop_start_expecting_block(GatewayCheckout $checkout, array $settings)
{
    try {
        $checkout->start(42, $settings);
    } catch (\Paymos\Plugin\InvoiceReplacementBlockedException $e) {
        return $e;
    }

    throw new RuntimeException('checkout must refuse to replace an invoice it cannot prove closed.');
}

function test_prestashop_gateway_checkout_does_not_replace_an_invoice_the_buyer_can_still_pay()
{
    // BUG-166: only awaiting_client is cancellable on the server; a network
    // picked, funds confirming, a part paid or a full payment keeps the old
    // invoice, and the order goes to manual review.
    foreach (array('awaiting_payment', 'confirming', 'underpaid_waiting', 'paid') as $status) {
        $store = new InMemoryInvoiceStore();
        $store->save(prestashop_snapshot(array('paymos_invoice_id' => 'inv_existing', 'amount' => '50.00', 'status' => 'awaiting_client')));
        $transport = new MockTransport(array(
            prestashop_problem(409, 'invoice_cannot_be_cancelled', 'Invoice cannot be cancelled in status ' . $status . '.'),
            prestashop_live_invoice_response('inv_existing', $status, time() + 600),
        ));
        $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);
        $adapter = new FakePrestaShopAdapter();

        $e = prestashop_start_expecting_block(new GatewayCheckout($store, $adapter, static function () use ($client) {
            return $client;
        }), prestashop_settings());

        assertSameValue($status, $e->result()->status(), $status . ': the blocking status is reported.');
        assertSameValue(array('POST', 'GET'), array_column($transport->requests(), 'method'), $status . ': cancel, read, and no create.');
        assertSameValue('inv_existing', $store->findByOrderId(42)['paymos_invoice_id'], $status . ': the old invoice stays.');
        assertSameValue('awaiting_client', $store->findByOrderId(42)['status'], $status . ': an open or paid status read here is not recorded.');
        assertSameValue(array(array('id_order' => 42, 'id_order_state' => 9)), $adapter->transitions, $status . ': the order moves to the manual-review state.');
        assertSameValue(1, count($adapter->notes), $status . ': the order gets a note.');
        assertContainsValue('inv_existing', $adapter->notes[0]['note'], $status . ': the note names the old invoice.');
    }
}

function test_prestashop_gateway_checkout_does_not_replace_an_invoice_the_server_answers_404_for()
{
    $store = new InMemoryInvoiceStore();
    $store->save(prestashop_snapshot(array('paymos_invoice_id' => 'inv_existing', 'amount' => '50.00', 'status' => 'awaiting_client')));
    $transport = new MockTransport(array(prestashop_problem(404, 'not_found', 'Invoice not found.')));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);
    $adapter = new FakePrestaShopAdapter();

    $e = prestashop_start_expecting_block(new GatewayCheckout($store, $adapter, static function () use ($client) {
        return $client;
    }), prestashop_settings());

    assertSameValue(\Paymos\Plugin\InvoiceReplacementResult::REASON_NOT_FOUND, $e->result()->reason(), 'the 404 is the reason.');
    assertSameValue(1, count($transport->requests()), 'no invoice is created after a 404.');
    assertSameValue(1, count($adapter->transitions), 'the order moves to manual review.');
}

function test_prestashop_gateway_checkout_does_not_renew_when_the_lookup_answers_404()
{
    $store = new InMemoryInvoiceStore();
    $store->save(prestashop_snapshot(array('paymos_invoice_id' => 'inv_existing', 'payment_url' => 'https://checkout.paymos.test/existing', 'status' => 'awaiting_client')));
    $transport = new MockTransport(array(
        prestashop_problem(404, 'not_found', 'Invoice not found.'),
        prestashop_problem(404, 'not_found', 'Invoice not found.'),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);

    prestashop_start_expecting_block(new GatewayCheckout($store, new FakePrestaShopAdapter(), static function () use ($client) {
        return $client;
    }), prestashop_settings());

    assertSameValue(array('GET', 'POST'), array_column($transport->requests(), 'method'), 'read, cancel attempt, and no create.');
    assertSameValue('ps_42_0', $store->findByOrderId(42)['external_order_id'], 'no new external order id is cut.');
}

function test_prestashop_gateway_checkout_cancels_the_old_invoice_in_its_own_environment()
{
    $store = new InMemoryInvoiceStore();
    $store->save(prestashop_snapshot(array('paymos_invoice_id' => 'inv_existing', 'status' => 'awaiting_client')));
    $sandbox = new MockTransport(array(prestashop_live_invoice_response('inv_existing', 'cancelled', time() + 600)));
    $live = new MockTransport(array(new HttpResponse(201, json_encode(array(
        'invoice_id' => 'inv_live',
        'status' => 'awaiting_client',
        'payment_url' => 'https://checkout.paymos.test/live',
    )), array())));
    $clients = array(
        'sandbox' => new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $sandbox),
        'live' => new Client(new ClientConfig('pk_live_123', 'sk_live_123', 'https://api.paymos.test'), $live),
    );

    $result = (new GatewayCheckout($store, new FakePrestaShopAdapter(), static function ($config, $environment = null) use ($clients) {
        return $clients[$environment === null ? $config->environment() : $environment];
    }))->start(42, prestashop_settings(array('PAYMOS_MODE' => 'live')));

    assertSameValue('https://checkout.paymos.test/live', $result['payment_url'], 'the live invoice is issued.');
    assertSameValue(array('POST'), array_column($sandbox->requests(), 'method'), 'the sandbox invoice is cancelled with sandbox credentials.');
    assertSameValue(array('POST'), array_column($live->requests(), 'method'), 'only the create goes to live.');
}

function test_prestashop_gateway_checkout_single_product_and_optional_fields()
{
    $store = new InMemoryInvoiceStore();
    $adapter = new FakePrestaShopAdapter();
    $adapter->orders[42] = prestashop_order(array(
        'total' => '47.00',
        'products' => array(
            array(
                'product_name' => "<b>Canvas</b> &amp; Blue\0",
                'description' => "<p>Museum grade</p><p>Cotton\0</p>",
                'product_quantity' => 2,
                'unit_price_tax_incl' => '24.50',
            ),
        ),
    ));
    $transport = new MockTransport(array(
        new HttpResponse(201, json_encode(array(
            'invoice_id' => 'inv_single',
            'status' => 'awaiting_client',
            'payment_url' => 'https://checkout.paymos.test/inv_single',
        )), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);

    (new GatewayCheckout($store, $adapter, static function () use ($client) {
        return $client;
    }))->start(42, prestashop_settings());

    $payload = json_decode($transport->requests()[0]['body'], true);
    assertSameValue('47', $payload['amount'], 'native grand total must remain intact.');
    assertSameValue(
        array(
            array(
                'title' => 'Canvas & Blue',
                'quantity' => 2,
                'unit_amount' => '24.50',
                'description' => "Museum grade\nCotton",
            ),
        ),
        $payload['products'],
        'single PrestaShop item must normalize cleanly.'
    );

    // Optional blank description must omit optional keys without failing the item.
    $checkout = new GatewayCheckout(new InMemoryInvoiceStore(), new FakePrestaShopAdapter());
    $minimal = $checkout->buildInvoicePayload('prj_123', '47.00', 'USD', 'ps_42_0', '77', array(
        array(
            'title' => 'Canvas · Blue',
            'description' => '<p>&nbsp;</p>',
            'quantity' => '2',
            'unit_amount' => '24.50',
        ),
    ));
    assertSameValue(
        array(array('title' => 'Canvas · Blue', 'quantity' => 2, 'unit_amount' => '24.50')),
        $minimal['products'],
        'blank description must be omitted without failing item.'
    );

    // Adapter formatting helper preserves precision without float rounding surprises.
    assertSameValue('24.50', PaymosPrestaShop\PrestaShopAdapter::formatUnitAmount('24.500000', 2), '6-decimal MySQL price must format to 2-decimal currency precision.');
    assertSameValue('10.001', PaymosPrestaShop\PrestaShopAdapter::formatUnitAmount('10.001000', 3), '3-decimal currency must keep 3rd decimal digit.');
    assertSameValue('16050', PaymosPrestaShop\PrestaShopAdapter::formatUnitAmount('16050.000000', 0), '0-decimal currency must drop zero decimals.');
    assertSameValue(array(), (new PaymosPrestaShop\PrestaShopAdapter())->getOrder(0), 'missing PrestaShop order still returns empty array.');
}

function test_prestashop_gateway_checkout_cart_products_and_all_or_nothing_fallback()
{
    $store = new InMemoryInvoiceStore();
    $adapter = new FakePrestaShopAdapter();
    $adapter->orders[42] = prestashop_order(array(
        'total' => '42.50',
        'products' => array(
            array(
                'title' => 'Canvas · Blue',
                'description' => '<p>Giclée print</p>',
                'quantity' => 2,
                'unit_amount' => '15.00',
            ),
            array('type' => 'shipping', 'title' => 'Carrier', 'quantity' => 1, 'unit_amount' => '5.00'),
            array('type' => 'discount', 'title' => 'Voucher', 'quantity' => 1, 'unit_amount' => '-5.00'),
            array(
                'title' => 'Notebook',
                'description' => null,
                'quantity' => 1,
                'unit_amount' => '12.50',
            ),
        ),
    ));
    $transport = new MockTransport(array(
        new HttpResponse(201, json_encode(array(
            'invoice_id' => 'inv_cart',
            'status' => 'awaiting_client',
            'payment_url' => 'https://checkout.paymos.test/inv_cart',
        )), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);

    (new GatewayCheckout($store, $adapter, static function () use ($client) {
        return $client;
    }))->start(42, prestashop_settings());

    $payload = json_decode($transport->requests()[0]['body'], true);
    assertSameValue('42.5', $payload['amount'], 'cart native grand total must be preserved.');
    assertSameValue(
        array(
            array(
                'title' => 'Canvas · Blue',
                'quantity' => 2,
                'unit_amount' => '15.00',
                'description' => 'Giclée print',
            ),
            array(
                'title' => 'Notebook',
                'quantity' => 1,
                'unit_amount' => '12.50',
            ),
        ),
        $payload['products'],
        'cart must include all valid lines and omit non-product lines.'
    );

    $fifty = array();
    for ($i = 1; $i <= 50; $i++) {
        $fifty[] = array('title' => 'Item ' . $i, 'quantity' => 1, 'unit_amount' => '1.00');
    }
    $fiftyOne = $fifty;
    $fiftyOne[] = array('title' => 'Item 51', 'quantity' => 1, 'unit_amount' => '1.00');

    $checkout = new GatewayCheckout(new InMemoryInvoiceStore(), new FakePrestaShopAdapter());
    $atMax = $checkout->buildInvoicePayload('prj_123', '50.00', 'USD', 'ps_42_0', '77', $fifty);
    assertSameValue(50, count($atMax['products']), 'exactly 50 items must be sent.');

    $invalidCarts = array(
        'fractional_qty' => array(
            array('title' => 'Valid', 'quantity' => 1, 'unit_amount' => '20.00'),
            array('title' => 'Fabric', 'quantity' => 1.5, 'unit_amount' => '10.00'),
        ),
        'empty_title' => array(
            array('title' => 'Valid', 'quantity' => 1, 'unit_amount' => '20.00'),
            array('title' => '   ', 'quantity' => 1, 'unit_amount' => '10.00'),
        ),
        'negative_unit_amount' => array(
            array('title' => 'Valid', 'quantity' => 1, 'unit_amount' => '20.00'),
            array('title' => 'Broken', 'quantity' => 1, 'unit_amount' => '-1.00'),
        ),
        'over_50' => $fiftyOne,
    );
    foreach ($invalidCarts as $label => $items) {
        $fallback = $checkout->buildInvoicePayload('prj_123', '42.50', 'USD', 'ps_42_0', '77', $items);
        assertSameValue('42.50', $fallback['amount'], $label . ': fallback preserves amount.');
        assertFalseValue(array_key_exists('products', $fallback), $label . ': invalid cart reverts to amount-only.');
    }
}

function test_prestashop_gateway_checkout_retry_replays_original_products_payload_and_money_guards()
{
    $store = new InMemoryInvoiceStore();
    $adapter = new FakePrestaShopAdapter();
    $adapter->orders[42] = prestashop_order(array(
        'total' => '59.00',
        'products' => array(
            array(
                'title' => 'Studio Pro',
                'description' => 'One-month access',
                'quantity' => 2,
                'unit_amount' => '24.50',
            ),
            array(
                'title' => 'Priority Support',
                'quantity' => 1,
                'unit_amount' => '12.00',
            ),
        ),
    ));

    $transport = new class (array(
        new RuntimeException('lost create response'),
        new HttpResponse(201, json_encode(array(
            'invoice_id' => 'inv_recovered',
            'status' => 'awaiting_client',
            'payment_url' => 'https://checkout.paymos.test/inv_recovered',
        )), array()),
        prestashop_live_invoice_response('inv_recovered', 'awaiting_client', time() + 600),
    )) implements \Paymos\Http\TransportInterface {
        private $script;
        private $requests = array();

        public function __construct(array $script)
        {
            $this->script = $script;
        }

        public function request($method, $url, array $headers, $body, $timeoutSeconds)
        {
            $this->requests[] = array('method' => (string) $method, 'url' => (string) $url, 'body' => (string) $body);
            $next = array_shift($this->script);
            if ($next instanceof \Throwable) {
                throw $next;
            }
            return $next;
        }

        public function requests()
        {
            return $this->requests;
        }
    };
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);
    $factory = static function () use ($client) { return $client; };

    $failed = false;
    try {
        (new GatewayCheckout($store, $adapter, $factory))->start(42, prestashop_settings());
    } catch (RuntimeException $e) {
        $failed = true;
    }
    assertTrueValue($failed, 'lost first create response must surface failure while keeping pending intent.');
    assertSameValue('creating', $store->findByOrderId(42)['status'], 'pending creation intent must remain saved.');

    // Order presentation metadata changes before retry, while amount/currency remain 59.00 USD.
    $adapter->orders[42]['products'] = array(
        array('title' => 'Mutated Product', 'quantity' => 3, 'unit_amount' => '15.00'),
    );

    $result = (new GatewayCheckout($store, $adapter, $factory))->start(42, prestashop_settings());
    assertSameValue('https://checkout.paymos.test/inv_recovered', $result['payment_url'], 'retry must bind recovered invoice.');

    $requests = $transport->requests();
    assertSameValue(3, count($requests), 'lost create, byte-identical replay, and liveness GET.');
    assertSameValue($requests[0]['body'], $requests[1]['body'], 'retry must replay the exact original JSON with original products[].');
    $replayed = json_decode($requests[1]['body'], true);
    assertSameValue('Studio Pro', $replayed['products'][0]['title'], 'replayed payload must keep original Studio Pro item.');
    assertSameValue('Priority Support', $replayed['products'][1]['title'], 'replayed payload must keep original second item.');
}

function test_prestashop_historical_pending_retry_projects_images_without_rewriting_json_or_recalculating_products()
{
    $store = new InMemoryInvoiceStore();
    $adapter = new FakePrestaShopAdapter();
    $adapter->orders[42]['products'] = array(array('title' => 'Changed native cart', 'quantity' => 1, 'unit_amount' => '100.00'));
    $historicalPayload = array(
        'project_id' => 'prj_123',
        'amount' => '100.00',
        'currency' => 'USD',
        'external_order_id' => 'ps_42_0',
        'client_id' => '77',
        'products' => array(
            array('title' => '  <b>Frozen original</b>  ', 'description' => "  Line 1\nLine 2 — Ω 🚀  ",
                'image_url' => 'https://shop.example/legacy.webp', 'quantity' => 2, 'unit_amount' => '12345678901234567890.123456789012345678'),
            array('title' => 'Support', 'quantity' => 1, 'unit_amount' => '0.00', 'image_url' => null),
        ),
    );
    // Simulate an old release's durable intent after its create response was lost.
    $pending = \PaymosPrestaShop\InvoiceCreation::pending(prestashop_snapshot(), $historicalPayload);
    $store->save($pending);
    $pending = $store->findByOrderId(42);
    $storedJson = $pending['payment_url'];
    $storedRow = serialize($pending);
    $transport = new class($store, $storedJson) implements \Paymos\Http\TransportInterface {
        public $requests = array();
        private $store;
        private $storedJson;
        public function __construct($store, $storedJson) { $this->store = $store; $this->storedJson = $storedJson; }
        public function request($method, $url, array $headers, $body, $timeoutSeconds)
        {
            assertSameValue($this->storedJson, $this->store->findByOrderId(42)['payment_url'], 'Pending JSON must remain frozen before the recovery POST.');
            $this->requests[] = array('method' => $method, 'body' => $body);
            throw new RuntimeException('Lost recovery response.');
        }
    };
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);
    $factory = static function () use ($client) { return $client; };

    $failed = false;
    try { (new GatewayCheckout($store, $adapter, $factory))->start(42, prestashop_settings()); }
    catch (RuntimeException $e) { $failed = true; }
    assertTrueValue($failed, 'Lost recovery response must leave the original intent pending.');
    assertSameValue(1, count($transport->requests), 'Historical retry must issue one recovery POST.');
    $expected = $historicalPayload;
    unset($expected['products'][0]['image_url'], $expected['products'][1]['image_url']);
    assertSameValue($expected, json_decode($transport->requests[0]['body'], true), 'Recovery may remove only image_url, preserving frozen text, exact prices, item order and financial identity.');
    assertSameValue($storedRow, serialize($store->findByOrderId(42)), 'Failed recovery must preserve the complete pending row byte-for-byte.');

    $tampered = $store->findByOrderId(42);
    $tampered['amount'] = '101.00';
    $store->save($tampered);
    $failed = false;
    try { (new GatewayCheckout($store, $adapter, $factory))->start(42, prestashop_settings()); }
    catch (RuntimeException $e) { $failed = true; }
    assertTrueValue($failed, 'Legacy projection must retain the original payload versus row amount guard.');
    assertSameValue(1, count($transport->requests), 'Mismatched persisted money must be rejected before any second HTTP request.');
    assertSameValue($storedJson, $store->findByOrderId(42)['payment_url'], 'Failed identity validation must not rewrite the historical JSON.');
}
