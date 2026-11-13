<?php
declare(strict_types=1);

function test_prestashop_paid_recovers_before_and_after_cms_commit()
{
    foreach (array('failBeforePayment', 'failAfterPayment') as $fault) {
        $store = new PaymosPrestaShop\InMemoryInvoiceStore();
        $store->save(prestashop_snapshot());
        $adapter = new FakePrestaShopAdapter();
        $adapter->$fault = true;
        $processor = new PaymosPrestaShop\CallbackProcessor($adapter, $store,
            new PaymosPrestaShop\InMemoryEventStore(), static function () {
                return prestashop_reverse_verify_client()[0];
            });
        $body = json_encode(prestashop_invoice_event('evt_crash_' . $fault, 'invoice.paid', 'paid'));
        $sig = prestashop_signed_header('whsec_sandbox', $body, 1709000000);
        $first = $processor->handle($body, $sig, prestashop_settings(), 1709000000);
        assertSameValue(false, $first->statusCode() === 200, 'Fault must remain retriable.');
        assertSameValue('created', $store->findByExternalOrderId('ps_42_0')['status'], 'No premature paid snapshot.');
        $second = $processor->handle($body, $sig, prestashop_settings(), 1709000000);
        assertSameValue(200, $second->statusCode(), 'Retry recovers.');
        assertSameValue('paid', $store->findByExternalOrderId('ps_42_0')['status'], 'Retry finalizes snapshot.');
        assertSameValue(1, count($adapter->transitions), 'CMS payment happens once.');
    }
}

/**
 * A47-02: the native paid transition commits, the snapshot write fails, the
 * merchant then moves the order on to Shipped. The retry (callback or
 * reconcile) must record the final snapshot without setting PS_OS_PAYMENT again.
 */
function test_prestashop_paid_recovery_keeps_a_later_fulfilment_state()
{
    $shipped = 4;
    foreach (array('callback', 'reconcile') as $route) {
        $store = new PaymosPrestaShop\InMemoryInvoiceStore();
        $store->save(prestashop_snapshot());
        $adapter = new FakePrestaShopAdapter();
        $adapter->failAfterPayment = true;
        $factory = static function () {
            return prestashop_reverse_verify_client()[0];
        };
        $processor = new PaymosPrestaShop\CallbackProcessor($adapter, $store, new PaymosPrestaShop\InMemoryEventStore(), $factory);
        $body = json_encode(prestashop_invoice_event('evt_fulfilled_' . $route, 'invoice.paid', 'paid'));
        $sig = prestashop_signed_header('whsec_sandbox', $body, 1709000000);

        $first = $processor->handle($body, $sig, prestashop_settings(), 1709000000);
        assertSameValue(false, $first->statusCode() === 200, $route . ': the failed pass stays retryable.');
        assertSameValue('created', $store->findByExternalOrderId('ps_42_0')['status'], $route . ': no snapshot after the failure.');
        assertSameValue(1, count($adapter->transitions), $route . ': the native paid transition committed once.');

        // The merchant ships the paid order before the retry arrives.
        $adapter->orders[42]['current_state'] = $shipped;
        $adapter->transitions[] = array('id_order' => 42, 'id_order_state' => $shipped);

        if ($route === 'callback') {
            assertSameValue(200, $processor->handle($body, $sig, prestashop_settings(), 1709000000)->statusCode(), 'callback: the retry is acknowledged.');
        } else {
            (new PaymosPrestaShop\Reconciler($store, $adapter, $factory))->run(prestashop_settings(), time());
        }

        assertSameValue($shipped, $adapter->orders[42]['current_state'], $route . ': the fulfilment state is kept.');
        assertSameValue(2, count($adapter->transitions), $route . ': no second paid transition is written.');
        assertSameValue('paid', $store->findByExternalOrderId('ps_42_0')['status'], $route . ': the snapshot records the final status.');
    }
}
