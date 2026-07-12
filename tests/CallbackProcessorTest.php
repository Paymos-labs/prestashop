<?php

declare(strict_types=1);

use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Http\HttpResponse;
use Paymos\Http\MockTransport;
use PaymosPrestaShop\CallbackProcessor;
use PaymosPrestaShop\InMemoryEventStore;
use PaymosPrestaShop\InMemoryInvoiceStore;

/**
 * @param array<string, mixed> $invoiceOverrides
 */
function prestashop_reverse_verify_client(array $invoiceOverrides = array())
{
    // The server trims trailing zeros on the wire: "100.00" -> "100". The
    // reverse-verify GET therefore returns "100" while the stored snapshot is
    // "100.00" — AmountGuard must treat them as equal.
    $invoice = array_replace_recursive(array(
        'invoice_id' => 'inv_123',
        'project_id' => 'prj_123',
        'status' => 'paid',
        'order' => array(
            'external_id' => 'ps_42_0',
            'amount' => '100',
            'currency' => 'USD',
        ),
    ), $invoiceOverrides);

    $transport = new MockTransport(array(
        new HttpResponse(200, json_encode($invoice), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);

    return array($client, $transport);
}

function test_prestashop_callback_marks_order_paid_after_verified_webhook_and_reverse_lookup()
{
    $store = new InMemoryInvoiceStore();
    $store->save(prestashop_snapshot());
    $adapter = new FakePrestaShopAdapter();
    list($client, $transport) = prestashop_reverse_verify_client();

    $body = json_encode(prestashop_invoice_event('evt_paid', 'invoice.paid', 'paid'));
    $signature = prestashop_signed_header('whsec_sandbox', $body, 1709000000);

    $result = (new CallbackProcessor($adapter, $store, new InMemoryEventStore(), static function () use ($client) {
        return $client;
    }))->handle($body, $signature, prestashop_settings(), 1709000000);

    assertSameValue(200, $result->statusCode(), 'verified webhook must return HTTP 200.');
    assertSameValue('paid', $store->findByExternalOrderId('ps_42_0')['status'], 'stored invoice status must be updated.');
    assertSameValue(1, count($transport->requests()), 'terminal webhook must reverse-verify invoice through API.');
    assertSameValue(1, count($adapter->transitions), 'paid event must transition the order once.');
    assertSameValue(5, $adapter->transitions[0]['id_order_state'], 'paid event must use the configured paid state id.');
}

function test_prestashop_callback_reverse_verify_accepts_server_trimmed_amount()
{
    // Explicit regression: snapshot "100.00", server GET returns "100".
    $store = new InMemoryInvoiceStore();
    $store->save(prestashop_snapshot(array('amount' => '100.00')));
    $adapter = new FakePrestaShopAdapter();
    list($client) = prestashop_reverse_verify_client(array('order' => array('amount' => '100')));

    $body = json_encode(prestashop_invoice_event('evt_trim', 'invoice.paid', 'paid'));
    $signature = prestashop_signed_header('whsec_sandbox', $body, 1709000000);

    $result = (new CallbackProcessor($adapter, $store, new InMemoryEventStore(), static function () use ($client) {
        return $client;
    }))->handle($body, $signature, prestashop_settings(), 1709000000);

    assertSameValue(200, $result->statusCode(), 'trimmed server amount must still verify.');
    assertSameValue(5, $adapter->transitions[0]['id_order_state'], 'order must be marked paid despite the trimmed amount.');
}

function test_prestashop_callback_rejects_bad_signature()
{
    $store = new InMemoryInvoiceStore();
    $store->save(prestashop_snapshot());
    $adapter = new FakePrestaShopAdapter();

    $body = json_encode(prestashop_invoice_event('evt_bad', 'invoice.paid', 'paid'));
    $signature = prestashop_signed_header('whsec_wrong', $body, 1709000000);

    $result = (new CallbackProcessor($adapter, $store, new InMemoryEventStore(), static function () {
        throw new RuntimeException('client must not be called for a bad signature.');
    }))->handle($body, $signature, prestashop_settings(), 1709000000);

    assertSameValue(401, $result->statusCode(), 'bad signature must return HTTP 401.');
    assertSameValue(0, count($adapter->transitions), 'bad signature must not mutate the order.');
}

function test_prestashop_callback_rejects_environment_mismatch()
{
    $store = new InMemoryInvoiceStore();
    $store->save(prestashop_snapshot());
    $adapter = new FakePrestaShopAdapter();

    // Signed with the live secret but is_test=false, while the snapshot is sandbox.
    $body = json_encode(prestashop_invoice_event('evt_live', 'invoice.paid', 'paid', array(
        'data' => array('is_test' => false),
    )));
    $signature = prestashop_signed_header('whsec_live', $body, 1709000000);

    $result = (new CallbackProcessor($adapter, $store, new InMemoryEventStore(), static function () {
        throw new RuntimeException('client must not be called after environment mismatch.');
    }))->handle($body, $signature, prestashop_settings(), 1709000000);

    assertSameValue(400, $result->statusCode(), 'environment mismatch must fail processing.');
    assertSameValue(0, count($adapter->transitions), 'environment mismatch must not mutate the order.');
}

function test_prestashop_callback_is_idempotent_for_duplicate_events()
{
    $store = new InMemoryInvoiceStore();
    $store->save(prestashop_snapshot());
    $adapter = new FakePrestaShopAdapter();
    $eventStore = new InMemoryEventStore();
    list($client) = prestashop_reverse_verify_client();
    $processor = new CallbackProcessor($adapter, $store, $eventStore, static function () use ($client) {
        return $client;
    });

    $body = json_encode(prestashop_invoice_event('evt_dup', 'invoice.paid', 'paid'));
    $signature = prestashop_signed_header('whsec_sandbox', $body, 1709000000);

    $first = $processor->handle($body, $signature, prestashop_settings(), 1709000000);
    $second = $processor->handle($body, $signature, prestashop_settings(), 1709000000);

    assertSameValue(200, $first->statusCode(), 'first webhook must pass.');
    assertSameValue(200, $second->statusCode(), 'duplicate webhook must return HTTP 200.');
    assertTrueValue($second->isDuplicate(), 'duplicate webhook must be flagged.');
    assertSameValue(1, count($adapter->transitions), 'duplicate webhook must not add a second transition.');
}

function test_prestashop_callback_holds_amount_mismatch_for_manual_review()
{
    // Order total changed to 150 after the 100 invoice was created. The paid
    // webhook must NOT mark it paid; it lands in manual review.
    $store = new InMemoryInvoiceStore();
    $store->save(prestashop_snapshot(array('amount' => '100.00')));
    $adapter = new FakePrestaShopAdapter();
    $adapter->orders[42] = prestashop_order(array('total' => '150.00'));
    list($client) = prestashop_reverse_verify_client();

    $body = json_encode(prestashop_invoice_event('evt_mismatch', 'invoice.paid', 'paid'));
    $signature = prestashop_signed_header('whsec_sandbox', $body, 1709000000);

    $result = (new CallbackProcessor($adapter, $store, new InMemoryEventStore(), static function () use ($client) {
        return $client;
    }))->handle($body, $signature, prestashop_settings(), 1709000000);

    assertSameValue(200, $result->statusCode(), 'an amount mismatch is recorded (200), not retried forever.');
    assertSameValue(9, $adapter->transitions[0]['id_order_state'], 'amount mismatch must move the order to manual review, not paid.');
    assertSameValue(1, count($adapter->notes), 'a mismatch note must be left for the merchant.');
    assertContainsValue('order amount changed', $adapter->notes[0]['note'], 'the note must explain the mismatch.');
}

function test_prestashop_callback_ignores_non_invoice_events()
{
    $store = new InMemoryInvoiceStore();
    $store->save(prestashop_snapshot());
    $adapter = new FakePrestaShopAdapter();

    $body = json_encode(array(
        'event_id' => 'evt_wd',
        'event_type' => 'withdrawal.completed',
        'version' => 1,
        'occurred_at' => 1709000000,
        'data' => array('status' => 'completed'),
    ));
    $signature = prestashop_signed_header('whsec_sandbox', $body, 1709000000);

    $result = (new CallbackProcessor($adapter, $store, new InMemoryEventStore(), static function () {
        throw new RuntimeException('non-invoice events must not reverse-verify.');
    }))->handle($body, $signature, prestashop_settings(), 1709000000);

    assertSameValue(200, $result->statusCode(), 'non-invoice events are acknowledged with 200.');
    assertSameValue(0, count($adapter->transitions), 'non-invoice events must not mutate any order.');
}
