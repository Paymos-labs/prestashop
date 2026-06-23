<?php

declare(strict_types=1);

use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Http\HttpResponse;
use Paymos\Http\MockTransport;
use PaymosPrestaShop\InMemoryInvoiceStore;
use PaymosPrestaShop\Reconciler;

function test_prestashop_reconciler_applies_paid_invoice_when_webhook_was_missed()
{
    $store = new InMemoryInvoiceStore();
    $store->save(prestashop_snapshot(array('created_at' => date('Y-m-d H:i:s', 1708990000))));
    $adapter = new FakePrestaShopAdapter();
    $transport = new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'paid',
            'order' => array(
                'external_id' => 'ps_42_0',
                'amount' => '100',
                'currency' => 'USD',
            ),
        )), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);

    $count = (new Reconciler($store, $adapter, static function () use ($client) {
        return $client;
    }))->run(prestashop_settings(), 1709000000);

    assertSameValue(1, $count, 'reconciler must count newly completed orders.');
    assertSameValue('paid', $store->findByOrderId(42)['status'], 'reconciler must update stored invoice status.');
    assertSameValue(5, $adapter->transitions[0]['id_order_state'], 'reconciler must apply the paid state to the order.');
}

function test_prestashop_reconciler_skips_snapshot_mismatch()
{
    $store = new InMemoryInvoiceStore();
    $store->save(prestashop_snapshot(array('created_at' => date('Y-m-d H:i:s', 1708990000))));
    $adapter = new FakePrestaShopAdapter();
    $transport = new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'other_project',
            'status' => 'paid',
            'order' => array(
                'external_id' => 'ps_42_0',
                'amount' => '100',
                'currency' => 'USD',
            ),
        )), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);

    $count = (new Reconciler($store, $adapter, static function () use ($client) {
        return $client;
    }))->run(prestashop_settings(), 1709000000);

    assertSameValue(0, $count, 'snapshot mismatch must not be counted.');
    assertSameValue(0, count($adapter->transitions), 'snapshot mismatch must not mutate the order.');
}
