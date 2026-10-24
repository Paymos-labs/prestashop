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

function test_prestashop_event_store_tells_a_locked_event_from_a_committed_one()
{
    // BUG-103: remember() says "seen" for both; isCommitted() must not.
    $db = new FakeDb();
    $first = new EventStore($db);
    assertTrueValue($first->remember('evt_db', 604800), 'first delivery takes the lock.');

    $retry = new EventStore($db);
    assertFalseValue($retry->remember('evt_db', 604800), 'a retry while the lock is held is not new.');
    assertFalseValue($retry->isCommitted('evt_db'), 'a locked, uncommitted event is not committed.');

    $first->commit();
    assertTrueValue($retry->isCommitted('evt_db'), 'after commit the event is committed.');

    $memory = new InMemoryEventStore();
    $memory->remember('evt_mem', 604800);
    assertFalseValue($memory->isCommitted('evt_mem'), 'in memory, locked only: not committed.');
    $memory->commit();
    assertTrueValue($memory->isCommitted('evt_mem'), 'in memory, committed.');
}

/**
 * A10-02: a delivery whose reservation expired must not delete the successor's
 * row, and while the successor is still busy a third delivery must be rejected
 * as in-flight (not as a duplicate) until the successor commits.
 */
function test_prestashop_stale_owner_release_does_not_touch_the_successor()
{
    $db = new FakeDb();
    $ownerA = new EventStore($db);
    assertTrueValue($ownerA->remember('evt_stale_release', 604800), 'delivery A reserves the event.');

    // Delivery A is stalled past its 300s reservation: rewind the row's expiry
    // so the next remember() prunes it and takes the id over.
    $db->expireEventRow('evt_stale_release', time() - 1);

    $ownerB = new EventStore($db);
    assertTrueValue($ownerB->remember('evt_stale_release', 604800), 'delivery B takes over the expired reservation.');
    $rowB = $db->eventRow('evt_stale_release');
    assertTrueValue(is_array($rowB), 'the successor row must exist.');
    $tokenB = is_array($rowB) ? (string) $rowB['owner_token'] : '';
    assertTrueValue($tokenB !== '', 'the successor row must carry its own owner token.');

    // A wakes up and fails: its release() must complete silently and leave B's row.
    $ownerA->release();
    assertTrueValue(is_array($db->eventRow('evt_stale_release')), 'a stale release must not delete the successor reservation.');
    assertSameValue($tokenB, (string) $db->eventRow('evt_stale_release')['owner_token'], 'the successor row must keep its own token.');

    // While B is busy, a third delivery is rejected as in-flight, not duplicate.
    $ownerC = new EventStore($db);
    assertFalseValue($ownerC->remember('evt_stale_release', 604800), 'a third delivery while B is busy must lose the race.');
    assertFalseValue($ownerC->isCommitted('evt_stale_release'), 'the event is not committed while B is still processing.');

    $verifier = new MultiEnvironmentWebhookVerifier(array('sandbox' => 'whsec_sandbox'), $ownerC);
    $body = json_encode(prestashop_invoice_event('evt_stale_release', 'invoice.paid', 'paid'));
    $signature = prestashop_signed_header('whsec_sandbox', $body, 1709000000);
    try {
        $verifier->process($signature, $body, 1709000000);
        throw new RuntimeException('an in-flight event must not verify as new.');
    } catch (\Paymos\Exception\EventInProgressException $e) {
        // busy409: the successor's live reservation must not be acknowledged.
    }

    assertTrueValue($ownerB->commit(), 'the successor commits its own reservation.');
    assertTrueValue($ownerC->isCommitted('evt_stale_release'), 'after B commits the event is a duplicate (committed200).');
}

/**
 * A10-02: a stale delivery's commit() must report lost ownership (false) and
 * must not extend the successor's short reservation into a committed one.
 */
function test_prestashop_stale_owner_commit_does_not_confirm_the_successor()
{
    $db = new FakeDb();
    $ownerA = new EventStore($db);
    assertTrueValue($ownerA->remember('evt_stale_commit', 604800), 'delivery A reserves the event.');

    $db->expireEventRow('evt_stale_commit', time() - 1);

    $ownerB = new EventStore($db);
    assertTrueValue($ownerB->remember('evt_stale_commit', 604800), 'delivery B takes over the expired reservation.');
    $reservedByB = (int) $db->eventRow('evt_stale_commit')['expires_at'];

    assertFalseValue($ownerA->commit(), 'a stale commit must report lost ownership, not success.');
    assertSameValue($reservedByB, (int) $db->eventRow('evt_stale_commit')['expires_at'], "a stale commit must not extend the successor's reservation.");
    assertFalseValue($ownerB->isCommitted('evt_stale_commit'), "B's row must still look busy, not committed.");

    $ownerC = new EventStore($db);
    assertFalseValue($ownerC->remember('evt_stale_commit', 604800), 'C is still rejected while B is busy.');

    assertTrueValue($ownerB->commit(), 'the successor commits its own reservation.');
    assertTrueValue($ownerC->isCommitted('evt_stale_commit'), 'after B commits the event is a duplicate.');
}

/**
 * A10-02: owner tokens come from random_bytes, not the clock — two reservations
 * taken within the same second can never share a token.
 */
function test_prestashop_owner_tokens_do_not_collide_within_the_same_second()
{
    $db = new FakeDb();
    $first = new EventStore($db);
    $second = new EventStore($db);

    assertTrueValue($first->remember('evt_token_a', 604800), 'first reservation.');
    assertTrueValue($second->remember('evt_token_b', 604800), 'second reservation in the same second.');

    $tokenA = (string) $db->eventRow('evt_token_a')['owner_token'];
    $tokenB = (string) $db->eventRow('evt_token_b')['owner_token'];

    assertTrueValue((bool) preg_match('/^[0-9a-f]{32}$/', $tokenA), 'owner token must be 16 random bytes as hex.');
    assertTrueValue((bool) preg_match('/^[0-9a-f]{32}$/', $tokenB), 'owner token must be 16 random bytes as hex.');
    assertTrueValue($tokenA !== $tokenB, 'two reservations in the same second must not share an owner token.');
    assertSameValue(
        (int) $db->eventRow('evt_token_a')['created_at'],
        (int) $db->eventRow('evt_token_b')['created_at'],
        'precondition: both rows were written in the same second.'
    );
}

/**
 * A10-02: commit() with nothing pending (or after the row is gone) is a quiet
 * non-success, never an error and never a recovery failure.
 */
function test_prestashop_zero_row_commit_is_reported_as_not_owned()
{
    $db = new FakeDb();
    $store = new EventStore($db);

    assertFalseValue($store->commit(), 'commit without a pending reservation must report non-success.');

    assertTrueValue($store->remember('evt_zero_rows', 604800), 'reserve before the row disappears.');
    $db->expireEventRow('evt_zero_rows', time() - 1);
    $successor = new EventStore($db);
    assertTrueValue($successor->remember('evt_zero_rows', 604800), 'the successor takes the expired id over.');
    assertFalseValue($store->commit(), 'a commit that matched zero rows must report lost ownership.');
    assertTrueValue(is_array($db->eventRow('evt_zero_rows')), 'the failed commit must leave the successor row in place.');
}
