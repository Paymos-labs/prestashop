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
