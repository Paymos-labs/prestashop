<?php

declare(strict_types=1);

use Paymos\Webhook\InMemoryEventStore;
use PaymosPrestaShop\CallbackProcessor;
use PaymosPrestaShop\InMemoryInvoiceStore;

// invoice-late-payment: a paid_late event on an invoice whose snapshot holds a FINAL status
// (expired) produces exactly ONE native order note — never a state, stock or payment action — and a
// sequential duplicate (distinct event id) stays silent.

function prestashop_paid_late_body($eventId)
{
    return json_encode(prestashop_invoice_event($eventId, 'invoice.paid_late', 'expired', array(
        'data' => array('paid_late_at' => 1777978800),
    )));
}

function prestashop_late_store()
{
    $store = new InMemoryInvoiceStore();
    $store->save(prestashop_snapshot(array('status' => 'expired')));
    return $store;
}

function test_prestashop_paid_late_notes_once_and_changes_nothing()
{
    $store = prestashop_late_store();
    $adapter = new FakePrestaShopAdapter();

    $body = prestashop_paid_late_body('evt_late_1');
    $signature = prestashop_signed_header('whsec_sandbox', $body, 1709000000);

    $result = (new CallbackProcessor($adapter, $store, new InMemoryEventStore()))->handle(
        $body,
        $signature,
        prestashop_settings(),
        1709000000
    );

    assertSameValue(1, count($adapter->notes), 'paid_late must add exactly one order note.');
    assertSameValue(42, $adapter->notes[0]['id_order'], 'the note belongs to the snapshot order.');
    assertTrueValue(strpos($adapter->notes[0]['note'], 'finished paying for this order after the invoice closed') !== false, 'the note names the late fact.');
    assertSameValue('evt_late_1', $store->findByExternalOrderId('ps_42_0')['paid_late_noted'], 'marker records the event id.');
    assertSameValue('expired', $store->findByExternalOrderId('ps_42_0')['status'], 'row status stays final.');
    assertTrueValue($result->statusCode() === 200 || $result->statusCode() === 204, 'the note outcome acknowledges.');
}

function test_prestashop_paid_late_sequential_duplicate_is_silent()
{
    $store = prestashop_late_store();
    $adapter = new FakePrestaShopAdapter();
    $eventStore = new InMemoryEventStore();
    $processor = new CallbackProcessor($adapter, $store, $eventStore);

    foreach (array('evt_late_1', 'evt_late_2') as $eventId) {
        $body = prestashop_paid_late_body($eventId);
        $processor->handle(
            $body,
            prestashop_signed_header('whsec_sandbox', $body, 1709000000),
            prestashop_settings(),
            1709000000
        );
    }

    assertSameValue(1, count($adapter->notes), 'a sequential duplicate paid_late must not add a second note.');
}
