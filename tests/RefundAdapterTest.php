<?php

declare(strict_types=1);

use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Http\HttpResponse;
use Paymos\Http\TransportInterface;
use Paymos\Plugin\CredentialSet;
use Paymos\Plugin\NativeProjectionResult;
use Paymos\Plugin\RefundIntent;
use Paymos\Plugin\RefundStates;
use Paymos\Plugin\VerifiedRefund;
use PaymosPrestaShop\RefundAdapter;
use PaymosPrestaShop\RefundIntentStore;
use PaymosPrestaShop\RefundNativePlatform;

/** SQL seam, not a native database or platform certification. */
final class PrestaShopRefundTestDb implements PaymosPrestaShop\DbInterface
{
    public $tables = array();
    public $queries = array();
    public $transaction = false;
    public $owner = true;
    public $failCommit = false;
    public $rollbackNative;
    private $backup;

    public function execute($sql)
    {
        $this->queries[] = $sql;
        if (strpos($sql, 'CREATE TABLE') === 0 || strpos($sql, 'DROP TABLE') === 0) { return true; }
        if (strpos($sql, 'INSERT IGNORE') === 0) {
            if (!isset($this->tables['installation'][1])) {
                $this->tables['installation'][1] = array('id' => 1, 'installation_id' => '11111111-1111-4111-8111-111111111111');
            }
            return true;
        }
        if ($sql === 'START TRANSACTION') {
            $this->backup = $this->tables;
            $this->transaction = true;
            return true;
        }
        if ($sql === 'COMMIT') {
            if ($this->failCommit) { $this->failCommit = false; throw new RuntimeException('commit_failed'); }
            $this->transaction = false;
            return true;
        }
        if ($sql === 'ROLLBACK') {
            $this->tables = $this->backup;
            $this->transaction = false;
            if ($this->rollbackNative !== null) { call_user_func($this->rollbackNative); }
            return true;
        }
        if (preg_match('/^UPDATE `[^`]*paymos_refund_(\w+)` SET (.*) WHERE (.*)$/s', $sql, $m)) {
            foreach ($this->tables[$m[1]] ?? array() as $key => $row) {
                if (!$this->matches($row, $m[3])) { continue; }
                foreach (array('payload', 'withdrawal_id', 'review_reason') as $field) {
                    if (preg_match('/`' . $field . '` = \'(.*?)\'(?:, `|$)/s', $m[2], $value)) {
                        $row[$field] = stripslashes($value[1]);
                    } elseif (strpos($m[2], '`' . $field . '` = NULL') !== false) { $row[$field] = null; }
                }
                if (preg_match('/`next_due` = (\d+)/', $m[2], $value)) { $row['next_due'] = (int) $value[1]; }
                if (strpos($m[2], '`poll_count` + 1') !== false) { $row['poll_count']++; }
                $this->tables[$m[1]][$key] = $row;
            }
            return true;
        }
        throw new RuntimeException('Unexpected fake SQL: ' . $sql);
    }

    public function getRows($sql)
    {
        $this->queries[] = $sql;
        if (preg_match('/SHOW TABLES LIKE \'[^\']*paymos_refund_(\w+)\'/', $sql, $show)) {
            return isset($this->tables[$show[1]]) ? array(array('table' => $show[1])) : array();
        }
        if (!preg_match('/FROM `[^`]*paymos_refund_(\w+)`(?: WHERE (.*?))?(?: ORDER BY .*?)?(?: LIMIT (\d+))?$/s', $sql, $m)) {
            throw new RuntimeException('Unexpected fake SELECT: ' . $sql);
        }
        $rows = array();
        foreach ($this->tables[$m[1]] ?? array() as $row) {
            if ($this->matches($row, $m[2] ?? '')) { $rows[] = $row; }
        }
        if (strpos($sql, 'ORDER BY `next_due`') !== false) {
            usort($rows, static function ($a, $b) { return array($a['next_due'], $a['operation_id'] ?? $a['withdrawal_id'])
                <=> array($b['next_due'], $b['operation_id'] ?? $b['withdrawal_id']); });
        }
        return isset($m[3]) && $m[3] !== '' ? array_slice($rows, 0, (int) $m[3]) : $rows;
    }

    private function matches(array $row, string $where): bool
    {
        if (preg_match_all('/`(\w+)` = \'([^\']*)\'/', $where, $fields, PREG_SET_ORDER)) {
            foreach ($fields as $field) { if (($row[$field[1]] ?? null) !== stripslashes($field[2])) { return false; } }
        }
        if (strpos($where, '`withdrawal_id` IS NULL') !== false && ($row['withdrawal_id'] ?? null) !== null) { return false; }
        if (preg_match('/`next_due` <= (\d+)/', $where, $due) && $row['next_due'] > (int) $due[1]) { return false; }
        if (preg_match('/`next_due` > (\d+).*`operation_id` > \'([^\']*)\'/s', $where, $cursor)) {
            if (array($row['next_due'], $row['operation_id']) <= array((int) $cursor[1], $cursor[2])) { return false; }
        }
        return true;
    }

    public function getRow($sql) { $rows = $this->getRows($sql); return $rows ? $rows[0] : null; }
    public function getValue($sql)
    {
        $this->queries[] = $sql;
        if (strpos($sql, 'SELECT GET_LOCK(') === 0 || strpos($sql, 'SELECT RELEASE_LOCK(') === 0) { return '1'; }
        if (strpos($sql, 'SELECT IS_USED_LOCK(') === 0) { return $this->owner ? '1' : '0'; }
        throw new RuntimeException('Unexpected fake scalar SQL');
    }
    public function insert($table, array $row)
    {
        preg_match('/paymos_refund_(\w+)$/', $table, $m);
        $name = $m[1];
        $key = $row['operation_id'] ?? $row['withdrawal_id'];
        foreach ($this->tables[$name] ?? array() as $existing) {
            if ($name === 'intent' && ($existing['external_order_id'] === $row['external_order_id']
                || ($existing['installation_id'] === $row['installation_id'] && $existing['request_token'] === $row['request_token']))) { return false; }
            if ($name === 'projection' && ($existing['withdrawal_id'] === $row['withdrawal_id']
                || ($existing['mode'] === $row['mode'] && $existing['native_id'] === $row['native_id']))) { return false; }
        }
        if (isset($this->tables[$name][$key])) { return false; }
        $this->tables[$name][$key] = $row;
        return true;
    }
    public function escape($value) { return addslashes((string) $value); }
}

final class PrestaShopRefundNativeOracle
{
    public $identity;
    public $documents = array();
    public $notes = array();
    public $stock = 5;
    public $paid = true;
    public $refundedMinor = '0';
    public $mailCount = 0;
    public $crash = false;
    public $afterWrite;
    public $mailCrash = false;
    public $logs = array();
    public $store;

    public function __construct()
    {
        $this->identity = array('id_order' => 42, 'id_shop' => 1, 'invoice_id' => 'inv_123', 'external_order_id' => 'ps_42_0',
            'project_id' => 'prj_123', 'environment' => 'sandbox', 'paid' => true, 'refunded' => false,
            'amount_minor' => '10000', 'currency' => 'USD', 'version' => 'oracle');
    }
    public function snapshot($orderId) { return $this->identity; }
    public function assertReady(bool $financial = true) {}
    public function prepare(RefundIntent $intent) {}
    public function fullDraft($snapshot) { return array('amount_minor' => $snapshot['amount_minor'], 'currency' => $snapshot['currency'], 'items' => array(1 => 2)); }
    public function validDraft($snapshot, $draft) { return $draft === $this->fullDraft($snapshot); }
    public function applyFull(RefundIntent $intent)
    {
        assertTrueValue($this->store->inTransaction(), 'Native financial write runs inside the store transaction.');
        if ($this->crash) { throw new RuntimeException('native_crash'); }
        $this->documents['7'] = $intent->operationId();
        $this->refundedMinor = '10000';
        $this->stock += 2;
        if ($this->afterWrite !== null) { call_user_func($this->afterWrite); }
        return array('id' => '7', 'mail' => array('template' => 'credit_slip'));
    }
    public function cashNote($orderId, VerifiedRefund $refund)
    {
        assertTrueValue($this->store->inTransaction(), 'Cash note and marker are atomic.');
        $id = (string) (count($this->notes) + 1);
        $this->notes[$id] = $refund->withdrawalId();
        return $id;
    }
    public function matchesMarker(RefundIntent $intent, NativeProjectionResult $result)
    {
        return $intent->mode() === 'full' ? isset($this->documents[$result->nativeId()]) : isset($this->notes[$result->nativeId()]);
    }
    public function sendMail($mail)
    {
        assertFalseValue($this->store->inTransaction(), 'Notification is sent after committed started.');
        $this->mailCount++;
        if ($this->mailCrash) { throw new RuntimeException('smtp_ack_lost'); }
        return true;
    }
    public function invoiceMapping($invoiceId) { return array('id_order' => 42, 'project_id' => 'prj_123', 'environment' => 'sandbox', 'paymos_invoice_id' => 'inv_123'); }
    public function log($message) { $this->logs[] = $message; }
}

final class PrestaShopRefundTransport implements TransportInterface
{
    public $requests = array();
    public $wire = array();
    public $lost = false;
    public $invoiceExtra = array();
    public $store;
    public $db;

    public function request($method, $url, array $headers, $body, $timeoutSeconds)
    {
        assertFalseValue($this->db->transaction, 'Provider HTTP never holds a database transaction.');
        $this->requests[] = array($method, $url, $body, $headers);
        if ($method === 'POST') {
            $payload = json_decode($body, true);
            assertSameValue(array('invoice_id', 'mode', 'external_order_id'), array_keys($payload), 'Wire uses mode-only server contract.');
            $intent = $this->store->findByExternalId($payload['external_order_id']);
            assertSameValue('unknown', $intent->cashState(), 'Unknown is durably committed before HTTP.');
            $id = $payload['mode'] === 'full' ? 'w_full' : 'w_over';
            if (isset($this->wire[$id]) && $this->wire[$id]['external_order_id'] !== $payload['external_order_id']) {
                $id .= '_' . (count($this->wire) + 1);
            }
            foreach ($this->wire as $existingId => $wire) {
                if ($wire['external_order_id'] === $payload['external_order_id']) { $id = $existingId; break; }
            }
            if (!isset($this->wire[$id])) {
                $this->wire[$id] = array('withdrawal_id' => $id, 'invoice_id' => 'inv_123', 'project_id' => 'prj_123',
                    'mode' => $payload['mode'], 'external_order_id' => $payload['external_order_id'], 'status' => 'created',
                    'amount' => $payload['mode'] === 'full' ? '100' : '20', 'currency' => 'USDT', 'network' => 'tron',
                    'first_sender' => 'T_first_sender', 'is_test' => true);
            }
            if ($this->lost) { $this->lost = is_int($this->lost) ? $this->lost - 1 : false; throw new RuntimeException('response_lost_after_server_commit'); }
            return new HttpResponse(201, json_encode(array('withdrawal_id' => $id)), array());
        }
        if (strpos($url, '/v1/invoices/') !== false) {
            return new HttpResponse(200, json_encode(array_replace(array('invoice_id' => 'inv_123', 'project_id' => 'prj_123', 'is_test' => true), $this->invoiceExtra)), array());
        }
        $id = basename($url);
        if (!isset($this->wire[$id])) { throw new RuntimeException('unexpected_get'); }
        return new HttpResponse(200, json_encode($this->wire[$id]), array('Cache-Control' => 'private, no-store'));
    }
}

function prestashop_refund_fixture()
{
    $db = new PrestaShopRefundTestDb();
    $clock = new class { public $now = 1709000000; };
    $store = new RefundIntentStore($db, static function () use ($clock) { return new DateTimeImmutable('@' . $clock->now); });
    $native = new PrestaShopRefundNativeOracle();
    $native->store = $store;
    $transport = new PrestaShopRefundTransport();
    $transport->store = $store;
    $transport->db = $db;
    $credentialBox = new class { public $values = array('project_id' => 'prj_123', 'capabilities' => array('invoice_refunds_v1'), 'refunds_enabled' => true); };
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);
    $adapter = new RefundAdapter($store, $native, static function () use ($credentialBox) { return $credentialBox->values; },
        static function () use ($client) { return $client; });
    return array($adapter, $store, $native, $transport, $db, $credentialBox, $clock);
}

function test_prestashop_ton_raw_pin_accepts_friendly_same_account_and_rejects_another()
{
    require_once __DIR__ . '/CmsTonPinContract.php';
    list($adapter, $store) = prestashop_refund_fixture();
    paymos_cms_ton_pin_contract($store, 'prestashop', $store->installationId(), '42');
}


function prestashop_refund_throws(callable $action, string $reason)
{
    try { $action(); } catch (RuntimeException $e) { assertSameValue($reason, $e->getMessage(), 'Correct refusal.'); return; }
    throw new RuntimeException('Expected refusal: ' . $reason);
}

function test_prestashop_refund_capability_and_new_gate_before_any_http()
{
    foreach (array(array(), array('other')) as $capabilities) {
        list($adapter, $store, $native, $transport, $db, $credentials) = prestashop_refund_fixture();
        $credentials->values['capabilities'] = $capabilities;
        prestashop_refund_throws(static function () use ($adapter) { $adapter->request(42, 'full', 'token-capability-0001'); }, 'refund_reconnect_required');
        assertSameValue(array(), $transport->requests, 'No HTTP without capability.');
    }
    $credentials->values['capabilities'] = array('invoice_refunds_v1');
    $credentials->values['refunds_enabled'] = false;
    prestashop_refund_throws(static function () use ($adapter) { $adapter->request(42, 'full', 'token-capability-0001'); }, 'refunds_not_enabled');
    assertSameValue(array(), $transport->requests, 'Disabled new gate makes no HTTP.');
}

function test_prestashop_refund_partial_and_manual_refund_rejected_before_http()
{
    list($adapter, $store, $native, $transport) = prestashop_refund_fixture();
    $draft = array('amount_minor' => '5000', 'currency' => 'USD', 'items' => array(1 => 1));
    prestashop_refund_throws(static function () use ($adapter, $draft) { $adapter->request(42, 'full', 'token-partial-00001', $draft); }, 'refund_partial_or_changed_draft');
    $native->identity['refunded'] = true;
    prestashop_refund_throws(static function () use ($adapter) { $adapter->request(42, 'full', 'token-manual-000001'); }, 'refund_native_payment_not_full');
    assertSameValue(array(), $transport->requests, 'Partial/manual requests never reach HTTP.');
}

function test_prestashop_refund_over20_then_full100_and_reorg_recompletion()
{
    list($adapter, $store, $native, $transport) = prestashop_refund_fixture();
    $over = $adapter->request(42, 'overpayment', 'token-overpayment-001');
    assertSameValue('created', $over->cashState(), 'Acceptance is pending.');
    assertSameValue(array(), $native->notes, 'No cash note before verified completion.');
    $transport->wire['w_over']['status'] = 'completed';
    $adapter->reconcile($over->operationId());
    assertSameValue(1, count($native->notes), 'One cash-only note.');
    assertSameValue('0', $native->refundedMinor, 'Overpayment leaves native refunded zero.');
    assertSameValue(5, $native->stock, 'Overpayment leaves stock unchanged.');
    assertTrueValue($native->paid, 'Order remains paid.');
    $full = $adapter->request(42, 'full', 'token-fullrefund-001');
    $transport->wire['w_over']['status'] = 'signed';
    $adapter->reconcile($over->operationId());
    assertSameValue('created', $store->getIntent($full->operationId())->cashState(), 'Old reorg cannot overwrite full operation.');
    $transport->wire['w_full']['status'] = 'completed';
    $adapter->reconcile($full->operationId());
    assertSameValue('10000', $native->refundedMinor, 'Full refunds original native paid total.');
    assertSameValue(1, count($native->documents), 'One full native document.');
    $transport->wire['w_full']['status'] = 'signed';
    $adapter->reconcile($full->operationId());
    assertSameValue('native_review', $store->getIntent($full->operationId())->projectionState(), 'Cash rewind retains historical document with review.');
    $transport->wire['w_full']['status'] = 'completed';
    $transport->wire['w_over']['status'] = 'completed';
    $adapter->reconcile($full->operationId());
    $adapter->reconcile($over->operationId());
    assertSameValue(1, count($native->documents), 'Recompletion reuses document.');
    assertSameValue(1, count($native->notes), 'Recompletion reuses cash note.');
    assertSameValue(7, $native->stock, 'Full native stock effect happens once.');
    assertSameValue(1, $native->mailCount, 'Native confirmation attempted once.');
    prestashop_refund_throws(static function () use ($adapter) { $adapter->request(42, 'full', 'token-new-afterfull'); }, 'refund_payment_unresolved_or_refunded');
    $posts = array_values(array_filter($transport->requests, static function ($r) { return $r[0] === 'POST'; }));
    assertSameValue(2, count($posts), 'Only overpayment and full cash requests.');
}

function test_prestashop_refund_unknown_reuses_committed_mode_key_after_restart()
{
    list($adapter, $store, $native, $transport, $db, $credentials) = prestashop_refund_fixture();
    $transport->lost = 2;
    $intent = $adapter->request(42, 'overpayment', 'token-unknown-00001');
    assertSameValue('unknown', $intent->cashState(), 'Lost responses leave one durable unknown operation.');
    prestashop_refund_throws(static function () use ($adapter) { $adapter->request(42, 'full', 'token-competing-001'); }, 'refund_payment_unresolved_or_refunded');
    $restartedStore = new RefundIntentStore($db);
    $restartedAdapter = new RefundAdapter($restartedStore, $native,
        static function () use ($credentials) { return $credentials->values; },
        static function () use ($transport) { return new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport); });
    $intent = $restartedAdapter->request(42, 'overpayment', 'token-unknown-00001');
    assertSameValue('created', $intent->cashState(), 'Restart recovers the original cash operation.');
    $posts = array_values(array_filter($transport->requests, static function ($r) { return $r[0] === 'POST'; }));
    assertSameValue(3, count($posts), 'Initial request and same-key replays only.');
    assertSameValue($posts[0][2], $posts[1][2], 'Replay body is byte-identical.');
    assertSameValue($posts[0][2], $posts[2][2], 'Restart preserves the wire body.');
    assertSameValue(1, count($db->tables['intent']), 'No extra local intent.');
    $credentials->values['capabilities'] = array();
    $before = count($transport->requests);
    prestashop_refund_throws(static function () use ($adapter) { $adapter->request(42, 'overpayment', 'token-unknown-00001'); }, 'refund_reconnect_required');
    assertSameValue($before, count($transport->requests), 'Replay also checks current capability.');
    prestashop_refund_throws(static function () use ($adapter) { $adapter->request(42, 'full', 'token-unknown-00001'); }, 'refund_request_conflict');
}

function test_prestashop_refund_write_once_binding_and_pin_conflict()
{
    list($adapter, $store, $native, $transport) = prestashop_refund_fixture();
    $intent = $adapter->request(42, 'full', 'token-binding-00001');
    $store->withPaymentLock($intent->paymentKey(), static function () use ($store, $intent) {
        assertSameValue('same', $store->bindWithdrawal($intent->operationId(), 'w_full'), 'Same binding is idempotent.');
        assertSameValue('conflict', $store->bindWithdrawal($intent->operationId(), 'w_other'), 'Binding cannot change.');
    });
    assertSameValue('w_full', $store->getIntent($intent->operationId())->withdrawalId(), 'Original binding preserved.');
    list($adapter, $store, $native, $transport) = prestashop_refund_fixture();
    $intent = $adapter->request(42, 'full', 'token-pinned-000001');
    $transport->wire['w_full']['status'] = 'completed';
    $transport->wire['w_full']['first_sender'] = 'T_different_sender';
    assertSameValue('show_review', $adapter->reconcile($intent->operationId()), 'Changed readonly recipient is review.');
    assertSameValue(array(), $native->documents, 'Bad evidence never projects.');
}

function test_prestashop_refund_scoped_signed_callback_and_disabled_read_recovery()
{
    list($adapter, $store, $native, $transport, $db, $credentials) = prestashop_refund_fixture();
    $intent = $adapter->request(42, 'overpayment', 'token-callback-0001');
    $data = $transport->wire['w_over'];
    $data['status'] = 'completed';
    $event = array('event_id' => 'evt_refund_callback_1', 'event_type' => 'withdrawal.completed', 'version' => 1,
        'occurred_at' => 1709000000, 'data' => $data);
    $processor = new PaymosPrestaShop\CallbackProcessor(new FakePrestaShopAdapter(), new PaymosPrestaShop\InMemoryInvoiceStore(),
        new PaymosPrestaShop\InMemoryEventStore(), null, $adapter);
    $body = json_encode($event);
    $result = $processor->handle($body, prestashop_signed_header('whsec_sandbox', $body, 1709000000), prestashop_settings(), 1709000000);
    assertSameValue(200, $result->statusCode(), 'Signed refund routes into adapter.');
    assertSameValue(array(), $native->notes, 'Callback completed cannot override current GET created.');
    unset($data['project_id']);
    $adapter->callback($data, 'sandbox');
    assertSameValue(array(), $native->notes, 'Actual server callback without project metadata still uses primary scoped GET.');
    $data['project_id'] = 'prj_foreign';
    $before = count($transport->requests);
    prestashop_refund_throws(static function () use ($adapter, $data) { $adapter->callback($data, 'sandbox'); }, 'refund_callback_scope_conflict');
    assertSameValue($before, count($transport->requests), 'Foreign scope rejected before HTTP.');
    $credentials->values['refunds_enabled'] = false;
    $transport->wire['w_over']['status'] = 'completed';
    $adapter->reconcile($intent->operationId());
    assertSameValue(1, count($native->notes), 'Disabling new submissions preserves accepted recovery.');
}

function test_prestashop_refund_projection_failure_and_exact_marker_recovery()
{
    list($adapter, $store, $native, $transport, $db) = prestashop_refund_fixture();
    $intent = $adapter->request(42, 'full', 'token-nativecrash-01');
    $transport->wire['w_full']['status'] = 'completed';
    $native->crash = true;
    assertSameValue('show_review', $adapter->reconcile($intent->operationId()), 'Native failure remains recoverable review.');
    assertSameValue(array(), $native->documents, 'Failure never invents financial success.');
    $native->crash = false;
    $adapter->reconcile($intent->operationId());
    $saved = $db->tables['intent'][$intent->operationId()];
    $payload = json_decode($saved['payload'], true);
    $payload['projection'] = null;
    $payload['projection_state'] = 'pending';
    $db->tables['intent'][$intent->operationId()]['payload'] = json_encode($payload);
    assertSameValue('resume_recovery', $adapter->reconcile($intent->operationId()), 'Exact committed native marker restores missing projection binding.');
    assertSameValue(1, count($native->documents), 'Recovery never writes native document twice.');
    assertSameValue(1, $native->mailCount, 'Recovery never resends delivered notification.');
}

function test_prestashop_refund_started_notification_becomes_unknown_without_resend()
{
    list($adapter, $store, $native, $transport) = prestashop_refund_fixture();
    $intent = $adapter->request(42, 'full', 'token-mailcrash-001');
    $transport->wire['w_full']['status'] = 'completed';
    $native->mailCrash = true;
    $adapter->reconcile($intent->operationId());
    $adapter->reconcile($intent->operationId());
    assertSameValue('unknown', $store->getIntent($intent->operationId())->toArray()['effects']['credit_slip:0'], 'Lost SMTP acknowledgement is unknown.');
    assertSameValue(1, $native->mailCount, 'Unknown mail has no automatic resend.');
}

function test_prestashop_refund_reconciler_history_due_backoff_and_lock_loss()
{
    list($adapter, $store, $native, $transport, $db, $credentials, $clock) = prestashop_refund_fixture();
    $intent = $adapter->request(42, 'overpayment', 'token-reconciler-01');
    $adapter->run();
    assertSameValue($clock->now + 60, $db->tables['intent'][$intent->operationId()]['next_due'], 'Initial backoff is one minute.');
    $clock->now += 60;
    $transport->wire['w_over']['status'] = 'completed';
    $adapter->run();
    assertSameValue($clock->now + 86400, $db->tables['intent'][$intent->operationId()]['next_due'], 'Completed records get a historical daily sweep.');
    assertSameValue(array(), $store->listDue(new DateTimeImmutable('@' . $clock->now), 100, null)->items(), 'Not due records are excluded.');
    $db->owner = false;
    prestashop_refund_throws(static function () use ($adapter, $intent) { $adapter->reconcile($intent->operationId()); }, 'refund_payment_lock_lost');
}

function test_prestashop_refund_install_idempotency_and_exact_native_minor()
{
    $db = new PrestaShopRefundTestDb();
    RefundIntentStore::install($db);
    RefundIntentStore::install($db);
    assertSameValue(8, count($db->queries), 'Four additive tables installed idempotently.');
    foreach ($db->queries as $sql) {
        assertContainsValue('CREATE TABLE IF NOT EXISTS', $sql, 'Upgrade preserves durable history.');
        assertContainsValue('ENGINE=InnoDB', $sql, 'Transaction capability required.');
    }
    assertSameValue('1234567890123456789012', RefundNativePlatform::minor('12345678901234567890.12', 2), 'No float or integer overflow.');
    prestashop_refund_throws(static function () { RefundNativePlatform::minor('1.001', 2); }, 'refund_native_amount_precision');
    assertTrueValue(RefundNativePlatform::captureMail(array('template' => 'credit_slip')), 'Unrelated mail is unaffected outside exact finalizer scope.');
}

function test_prestashop_refund_failed_cancelled_preserve_native_and_explicit_new_token()
{
    foreach (array('failed', 'cancelled') as $state) {
        list($adapter, $store, $native, $transport) = prestashop_refund_fixture();
        $intent = $adapter->request(42, 'full', 'token-terminal-0001');
        $transport->wire['w_full']['status'] = $state;
        $adapter->reconcile($intent->operationId());
        assertSameValue($state, $store->getIntent($intent->operationId())->cashState(), 'Definitive terminal state is persisted.');
        assertSameValue(array(), $native->documents, 'Unsuccessful cash creates no native document.');
        $oldKey = $intent->externalOrderId();
        $new = $adapter->request(42, 'full', 'token-explicit-new1');
        assertSameValue('created', $new->cashState(), 'Fresh explicit action binds its own new Withdrawal.');
        assertTrueValue($new->withdrawalId() !== $intent->withdrawalId(), 'New action does not reuse the terminal Withdrawal.');
        assertTrueValue($oldKey !== $new->externalOrderId(), 'Explicit action after failure has a new immutable key.');
        assertSameValue($state, $store->getIntent($intent->operationId())->cashState(), 'Historical terminal intent is retained.');
    }
}

function test_prestashop_refund_unbound_full_is_review_and_overpayment_is_cash_only()
{
    foreach (array('full', 'overpayment') as $mode) {
        list($adapter, $store, $native, $transport, $db) = prestashop_refund_fixture();
        $wire = array('withdrawal_id' => 'w_unbound', 'invoice_id' => 'inv_123', 'mode' => $mode,
            'external_order_id' => 'dashboard-unbound-key', 'status' => 'completed', 'amount' => '20',
            'currency' => 'USDT', 'network' => 'tron', 'first_sender' => 'T_first', 'is_test' => true);
        $transport->wire['w_unbound'] = $wire;
        $adapter->callback($wire, 'sandbox');
        $adapter->callback($wire, 'sandbox');
        assertSameValue(array(), $native->documents, 'Unbound cash never invents a native financial draft.');
        assertSameValue('0', $native->refundedMinor, 'Unbound full/over never mutates native refunded amount.');
        assertSameValue(5, $native->stock, 'Unbound paths never restock.');
        assertSameValue($mode === 'overpayment' ? 1 : 0, count($native->notes), 'Only known overpayment creates one cash note.');
        assertSameValue($mode === 'full' ? 'refund_unbound_full_draft_missing' : null,
            $db->tables['unbound']['w_unbound']['review_reason'], 'Unbound full visibly requires review.');
        assertSameValue(0, count(array_filter($transport->requests, static function ($r) { return $r[0] === 'POST'; })), 'Unbound cash recovery never sends money.');
    }
}

function test_prestashop_refund_lost_native_commit_rolls_back_marker_and_document()
{
    list($adapter, $store, $native, $transport, $db) = prestashop_refund_fixture();
    $intent = $adapter->request(42, 'full', 'token-atomiccommit1');
    $transport->wire['w_full']['status'] = 'completed';
    $db->rollbackNative = static function () use ($native) { $native->documents = array(); $native->stock = 5; $native->refundedMinor = '0'; };
    $native->afterWrite = static function () use ($db, $native) { $db->failCommit = true; $native->afterWrite = null; };
    $adapter->reconcile($intent->operationId());
    assertSameValue(array(), $db->tables['projection'] ?? array(), 'Failed native transaction leaves no marker.');
    assertSameValue(array(), $native->documents, 'Native transactional rollback leaves no document.');
    $adapter->reconcile($intent->operationId());
    assertSameValue(1, count($native->documents), 'Recovery creates one financial record.');
}

function test_prestashop_refund_native_full_draft_preserves_tax_shipping_and_defaults()
{
    $native = new RefundNativePlatform(new RefundIntentStore(new PrestaShopRefundTestDb()));
    $snapshot = array('amount_minor' => '12000', 'currency' => 'USD', 'delivered' => true, 'returns_enabled' => false,
        'shipping' => '20', 'discounts' => '0', 'tax_included' => false, 'details' => array(array('id_order_detail' => '1',
            'product_quantity' => '2', 'total_price_tax_incl' => '96', 'total_price_tax_excl' => '80')));
    $draft = $native->fullDraft($snapshot);
    assertSameValue('partial', $draft['command'], 'Delivered orders use the native partial command with a full draft.');
    assertSameValue('80', $draft['items'][1]['amount'], 'Native input follows the saved tax display method.');
    assertSameValue('20', $draft['shipping'], 'Native shipping is preserved.');
    assertFalseValue($draft['restock'], 'Native partial default does not restock.');
    assertTrueValue($native->validDraft($snapshot, $draft), 'Full draft is accepted.');
    $changed = $draft;
    $changed['items'][1]['quantity'] = 1;
    assertFalseValue($native->validDraft($snapshot, $changed), 'Arbitrary native partial quantity is refused.');
    $changed = $draft;
    $changed['items'][1]['amount'] = '96';
    assertFalseValue($native->validDraft($snapshot, $changed), 'Wrong native tax amount is refused.');
    $changed = $draft;
    $changed['shipping'] = '19';
    assertFalseValue($native->validDraft($snapshot, $changed), 'Partial shipping is refused.');
    assertFalseValue($native->validDraft($snapshot, array('amount_minor' => '12000', 'currency' => 'USD')), 'Incomplete draft is refused.');
    $snapshot['delivered'] = false;
    assertSameValue('partial', $native->fullDraft($snapshot)['command'], 'Full refund remains available when merchandise returns are disabled.');
    $snapshot['returns_enabled'] = true;
    $standard = $native->fullDraft($snapshot);
    assertSameValue('standard', $standard['command'], 'Native standard entry retains automatic restock.');
    assertTrueValue($standard['restock'], 'Standard native default is saved.');
}

function test_prestashop_refund_crash_after_started_does_not_invoke_sender()
{
    list($adapter, $store, $native, $transport, $db) = prestashop_refund_fixture();
    $intent = $adapter->request(42, 'full', 'token-started-crash');
    $transport->wire['w_full']['status'] = 'completed';
    $native->mailCrash = true;
    $adapter->reconcile($intent->operationId());
    $payload = json_decode($db->tables['intent'][$intent->operationId()]['payload'], true);
    $payload['effects']['credit_slip:0'] = 'started';
    $db->tables['intent'][$intent->operationId()]['payload'] = json_encode($payload);
    $native->mailCount = 0;
    $adapter->reconcile($intent->operationId());
    assertSameValue(0, $native->mailCount, 'A persisted started claim never invokes sender after restart.');
    assertSameValue('unknown', $store->getIntent($intent->operationId())->toArray()['effects']['credit_slip:0'], 'Interrupted started claim becomes visibly unknown.');
}

function test_prestashop_refund_preview_is_readonly_and_capability_scoped()
{
    list($adapter, $store, $native, $transport, $db, $credentials) = prestashop_refund_fixture();
    $transport->invoiceExtra['refund_summary'] = array('full_refund_amount' => '120', 'overpayment_refund_amount' => '20',
        'can_refund_full' => true, 'can_refund_overpayment' => true, 'sender_address' => 'T_first');
    $summary = $adapter->preview(42);
    assertSameValue('20', $summary['overpayment_refund_amount'], 'Extra amount comes unchanged from the server.');
    assertTrueValue($summary['can_refund_full'], 'Server full eligibility displayed.');
    $credentials->values['capabilities'] = array();
    $before = count($transport->requests);
    prestashop_refund_throws(static function () use ($adapter) { $adapter->preview(42); }, 'refund_reconnect_required');
    assertSameValue($before, count($transport->requests), 'Even preview has no HTTP without capability.');
    $credentials->values['capabilities'] = array('invoice_refunds_v1');
    $transport->invoiceExtra['project_id'] = 'prj_foreign';
    prestashop_refund_throws(static function () use ($adapter) { $adapter->preview(42); }, 'refund_summary_scope_invalid');
}

function test_prestashop_refund_guard_checks_authenticated_employee_csrf_permission_and_shop()
{
    $module = new class extends Paymos {
        public $context;
        public $active = true;
        public function __construct() {}
    };
    $employee = new class {
        public $id_profile = 2;
        public $logged = true;
        public function isLoggedBack() { return $this->logged; }
    };
    $module->context = (object) array('employee' => $employee, 'shop' => (object) array('id' => 1));
    $_SERVER['REQUEST_METHOD'] = 'POST';
    Tools::$values = array('paymos_refund_csrf' => 'admin-csrf-AdminModules');
    Profile::$access = array('edit' => '1');
    $module->assertRefundPermission(42);
    foreach (array('csrf', 'permission', 'shop', 'login', 'method') as $case) {
        Tools::$values['paymos_refund_csrf'] = $case === 'csrf' ? 'foreign' : 'admin-csrf-AdminModules';
        Profile::$access['edit'] = $case === 'permission' ? '0' : '1';
        Order::$shopId = $case === 'shop' ? 2 : 1;
        $employee->logged = $case !== 'login';
        $_SERVER['REQUEST_METHOD'] = $case === 'method' ? 'GET' : 'POST';
        prestashop_refund_throws(static function () use ($module) { $module->assertRefundPermission(42); }, 'refund_admin_forbidden');
    }
    Tools::$values = array();
    Profile::$access = array('view' => '1', 'edit' => '1');
    Order::$shopId = 1;
    unset($_SERVER['REQUEST_METHOD']);
}

function test_prestashop_refund_connect_capability_survives_sdk_normalized_envelope()
{
    if (!defined('_COOKIE_KEY_')) { define('_COOKIE_KEY_', 'unit-test-installation-key-not-a-credential'); }
    $module = (new ReflectionClass('Paymos'))->newInstanceWithoutConstructor();
    $credentials = CredentialSet::normalize(array('sandbox' => array('api_key' => 'pk_test_123', 'api_secret' => 'sk_test_123',
        'project_id' => 'prj_123', 'webhook_secret' => 'whsec_123', 'capabilities' => array('invoice_refunds_v1'),
        'refund_contract_version' => 1, 'refunds_enabled' => true)));
    Configuration::$values['PAYMOS_CREDENTIALS_V1'] = Paymos\Plugin\AesGcmEnvelope::seal(array('schema' => 1, 'environments' => $credentials),
        _COOKIE_KEY_, 'paymos-prestashop-credentials-v1');
    $values = $module->paymosRefundCredentials('sandbox');
    assertTrueValue(CredentialSet::hasCapability($values, 'invoice_refunds_v1'), 'SDK capability survives stored Connect envelope.');
    assertTrueValue(CredentialSet::refundsEnabled($values), 'SDK new-action gate survives stored Connect envelope.');
    assertSameValue(array('invoice_refunds_v1'), $module->paymosSettings()['PAYMOS_SANDBOX_CAPABILITIES'], 'Shared settings preserve the SDK-normalized capability.');
    Configuration::$values = array();
}

function test_prestashop_refund_store_pages_fairly_and_unresolved_24h_enters_review()
{
    list($adapter, $store, $native, $transport, $db, $credentials, $clock) = prestashop_refund_fixture();
    $installation = $store->installationId();
    for ($i = 0; $i < 3; $i++) {
        $intent = new RefundIntent(array('operation_id' => RefundIntentStore::uuid(), 'request_token' => 'token-page-000000' . $i,
            'installation_id' => $installation, 'platform' => 'prestashop', 'native_payment_key' => 'prestashop:' . (100 + $i),
            'invoice_id' => 'inv_page_' . $i, 'project_id' => 'prj_123', 'environment' => 'sandbox', 'mode' => 'overpayment'));
        $store->withPaymentLock($intent->paymentKey(), static function () use ($store, $intent) { $store->createIntent($intent); });
    }
    $page1 = $store->listDue(new DateTimeImmutable('@' . $clock->now), 2, null);
    $page2 = $store->listDue(new DateTimeImmutable('@' . $clock->now), 2, $page1->nextCursor());
    assertSameValue(2, count($page1->items()), 'Bounded first page.');
    assertSameValue(1, count($page2->items()), 'Remaining historical operation is not dropped.');
    assertSameValue(null, $page2->nextCursor(), 'Pagination ends after the last record.');
    list($adapter, $store, $native, $transport, $db, $credentials, $clock) = prestashop_refund_fixture();
    $intent = $adapter->request(42, 'overpayment', 'token-timeout-00001');
    foreach (array(60, 120, 300, 900, 3600, 3600) as $delay) {
        $store->withPaymentLock($intent->paymentKey(), static function () use ($store, $intent) { $store->schedule($intent->operationId()); });
        assertSameValue($clock->now + $delay, $db->tables['intent'][$intent->operationId()]['next_due'], 'Contract backoff delay.');
        $clock->now += $delay;
    }
    $clock->now += 86401;
    $store->withPaymentLock($intent->paymentKey(), static function () use ($store, $intent) { $store->schedule($intent->operationId()); });
    assertSameValue('refund_unresolved_24h', $store->getIntent($intent->operationId())->toArray()['review_reason'], 'Unresolved age does not silently create a new request.');
}

function test_prestashop_refund_uninstall_retains_history_mapping_and_installation_identity()
{
    list($adapter, $store, $native, $transport, $db) = prestashop_refund_fixture();
    $adapter->request(42, 'overpayment', 'token-uninstall-001');
    $installation = $store->installationId();
    PaymosPrestaShop\Migrations::uninstall($db);
    $drops = array_values(array_filter($db->queries, static function ($sql) { return strpos($sql, 'DROP TABLE') === 0; }));
    assertSameValue(1, count($drops), 'Only event reservations are dropped when refund history exists.');
    assertSameValue($installation, (new RefundIntentStore($db))->installationId(), 'Reinstall does not rotate the refund namespace.');
    assertSameValue(1, count($db->tables['intent']), 'Durable request survives uninstall.');
}

function test_prestashop_refund_database_disables_native_query_cache_and_fails_closed()
{
    $nativeDb = new class {
        public $cache;
        public function executeS($sql, $array, $cache) { $this->cache = $cache; return array(array('value' => '1')); }
        public function execute($sql) { return false; }
        public function insert($table, $row, $nulls, $cache, $type, $prefix) {
            assertTrueValue($nulls, 'Nullable unique binding is stored as SQL NULL.');
            assertFalseValue($cache, 'Native write does not reuse cached query results.');
            assertFalseValue($prefix, 'Fully prefixed table is not prefixed twice.');
            return true;
        }
        public function escape($value, $html) { return addslashes($value); }
    };
    $database = new PaymosPrestaShop\RefundDatabase($nativeDb);
    assertSameValue('1', $database->getValue('SELECT 1'), 'Master read returns the current value.');
    assertFalseValue($nativeDb->cache, 'Lock ownership and recovery reads bypass native cache.');
    assertTrueValue($database->insert('ps_paymos_refund_intent', array('withdrawal_id' => null)), 'Native insert adapter uses the expected flags.');
    prestashop_refund_throws(static function () use ($database) { $database->execute('COMMIT'); }, 'refund_database_write_failed');
}
