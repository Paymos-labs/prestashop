<?php

declare(strict_types=1);

use Paymos\Webhook\WebhookEvent;
use PaymosPrestaShop\OrderMapper;

function test_prestashop_order_mapper_marks_paid_on_paid_event()
{
    $adapter = new FakePrestaShopAdapter();
    $event = new WebhookEvent(prestashop_invoice_event('evt_paid', 'invoice.paid', 'paid'));

    $paid = (new OrderMapper($adapter))->apply($event, prestashop_snapshot());

    assertTrueValue($paid, 'a paid event returns true.');
    assertSameValue(5, $adapter->transitions[0]['id_order_state'], 'paid event transitions to the paid state.');
}

function test_prestashop_order_mapper_records_tx_hash_on_paid()
{
    $adapter = new FakePrestaShopAdapter();
    $event = new WebhookEvent(prestashop_invoice_event('evt_paid_tx', 'invoice.paid', 'paid', array(
        'data' => array(
            'payment' => array(
                'transfers' => array(
                    array('tx_hash' => '0xabc123', 'explorer_url' => 'https://etherscan.io/tx/0xabc123', 'status' => 'confirmed'),
                ),
            ),
        ),
    )));

    $paid = (new OrderMapper($adapter))->apply($event, prestashop_snapshot());

    assertTrueValue($paid, 'a paid event with a transfer returns true.');
    assertSameValue(1, count($adapter->notes), 'the on-chain transaction must be recorded as an order note.');
    assertContainsValue('0xabc123', $adapter->notes[0]['note'], 'the note must carry the tx hash.');
    assertContainsValue('etherscan.io', $adapter->notes[0]['note'], 'the note must carry the explorer URL.');
}

function test_prestashop_order_mapper_maps_confirming_to_confirming_state()
{
    $adapter = new FakePrestaShopAdapter();
    $event = new WebhookEvent(prestashop_invoice_event('evt_conf', 'invoice.confirming', 'confirming'));

    (new OrderMapper($adapter))->apply($event, prestashop_snapshot());

    assertSameValue(2, $adapter->transitions[0]['id_order_state'], 'confirming maps to the confirming state.');
}

function test_prestashop_order_mapper_maps_expired_to_cancelled_state()
{
    $adapter = new FakePrestaShopAdapter();
    $event = new WebhookEvent(prestashop_invoice_event('evt_exp', 'invoice.expired', 'expired'));

    (new OrderMapper($adapter))->apply($event, prestashop_snapshot());

    assertSameValue(6, $adapter->transitions[0]['id_order_state'], 'expired maps to the cancelled state.');
}

function test_prestashop_order_mapper_roll_back_guard_protects_paid_order()
{
    // A late "cancelled" arrives after the order is already paid — it must be
    // ignored, not downgrade the order.
    $adapter = new FakePrestaShopAdapter();
    $adapter->paid[42] = true;
    $event = new WebhookEvent(prestashop_invoice_event('evt_late_cancel', 'invoice.cancelled', 'cancelled'));

    $paid = (new OrderMapper($adapter))->apply($event, prestashop_snapshot());

    assertFalseValue($paid, 'a late cancel on a paid order returns false.');
    assertSameValue(0, count($adapter->transitions), 'roll-back guard must block the downgrade transition.');
    assertSameValue(1, count($adapter->notes), 'the ignored out-of-order event must be audited.');
    assertContainsValue('out-of-order', $adapter->notes[0]['note'], 'the audit note must explain why it was ignored.');
}

function test_prestashop_order_mapper_ignores_underpaid_waiting()
{
    $adapter = new FakePrestaShopAdapter();
    $event = new WebhookEvent(prestashop_invoice_event('evt_uw', 'invoice.underpaid_waiting', 'underpaid_waiting'));

    (new OrderMapper($adapter))->apply($event, prestashop_snapshot());

    // underpaid_waiting -> ACTION_AWAITING_PAYMENT -> pending state (stays awaiting).
    assertSameValue(1, $adapter->transitions[0]['id_order_state'], 'underpaid_waiting keeps the order awaiting payment.');
}

function test_prestashop_order_mapper_does_nothing_for_unmapped_event()
{
    $adapter = new FakePrestaShopAdapter();
    $event = new WebhookEvent(prestashop_invoice_event('evt_unknown', 'invoice.something_new', 'totally_unknown'));

    $paid = (new OrderMapper($adapter))->apply($event, prestashop_snapshot());

    assertFalseValue($paid, 'an unmapped event never marks paid.');
    assertSameValue(0, count($adapter->transitions), 'an unmapped event causes no transition.');
}
