<?php

declare(strict_types=1);

use Paymos\Exception\DuplicateEventException;
use Paymos\Webhook\MultiEnvironmentWebhookVerifier;
use PaymosPrestaShop\EventStore;
use PaymosPrestaShop\InMemoryEventStore;

function test_prestashop_event_store_blocks_duplicate_after_commit()
{
    $store = new EventStore(new FakeDb());
    $body = json_encode(prestashop_invoice_event('evt_1', 'invoice.paid', 'paid'));
    $signature = prestashop_signed_header('whsec_sandbox', $body, 1709000000);
    $verifier = new MultiEnvironmentWebhookVerifier(array('sandbox' => 'whsec_sandbox'), $store);

    $verifier->process($signature, $body, 1709000000);
    $store->commit();

    try {
        $verifier->process($signature, $body, 1709000000);
    } catch (DuplicateEventException $e) {
        assertTrueValue(true, 'duplicate exception expected.');

        return;
    }

    throw new RuntimeException('Committed event id must block duplicate webhook processing.');
}

function test_prestashop_event_store_release_allows_retry()
{
    $store = new EventStore(new FakeDb());

    assertTrueValue($store->remember('evt_retry', 3600), 'first remember must reserve event id.');
    $store->release();
    assertTrueValue($store->remember('evt_retry', 3600), 'released event id must be retriable.');
}

function test_prestashop_event_store_blocks_duplicate_before_commit()
{
    $store = new EventStore(new FakeDb());

    assertTrueValue($store->remember('evt_dup', 3600), 'first remember reserves the id.');
    assertFalseValue($store->remember('evt_dup', 3600), 'a second remember of the same id (race) must lose.');
}

function test_prestashop_in_memory_event_store_matches_db_semantics()
{
    $store = new InMemoryEventStore();

    assertTrueValue($store->remember('evt_mem', 3600), 'first remember reserves the id.');
    assertFalseValue($store->remember('evt_mem', 3600), 'duplicate remember loses.');
    $store->release();
    assertTrueValue($store->remember('evt_mem', 3600), 'released id is retriable.');
}
