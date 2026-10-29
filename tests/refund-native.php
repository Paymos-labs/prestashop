<?php

declare(strict_types=1);

/**
 * Approved external stand only; never boot a shop on the developer machine.
 * Usage: PAYMOS_REFUND_NATIVE_STAND=1 php refund-native.php /approved/driver.php
 * The driver boots the actual package and returns a PrestaShopRefundStandDriver.
 * Each observation must come from native DB/UI/mail evidence, not platform stubs.
 */
interface PrestaShopRefundStandDriver
{
    /** version, shop_root, paymos_revision, same_master_connection, transactional_tables. */
    public function metadata(): array;

    /** Actual measured values for the scenario's keys, plus existing evidence file paths. */
    public function runScenario(string $scenario): array;
}

$scenarios = array(
    'protected-entry' => array('permission_rejected' => true, 'csrf_rejected' => true, 'foreign_shop_rejected' => true,
        'native_command_preflight_before_write' => true, 'partial_provider_posts' => 0, 'legacy_capability_provider_http' => 0),
    'over20-full100' => array('over_notes' => 1, 'over_native_refunded_minor' => '0', 'over_order_paid' => true,
        'over_stock_delta' => 0, 'full_documents' => 1, 'full_native_refunded_minor' => '10000', 'provider_withdrawals' => 2),
    'unknown-restart' => array('intents' => 1, 'provider_withdrawals' => 1, 'same_mode_key_after_restart' => true,
        'pending_documents' => 0, 'intent_committed_before_http' => true, 'http_in_db_transaction' => false),
    'native-crash' => array('document_and_marker_same_commit' => true, 'recovered_documents' => 1,
        'stock_effect_count' => 1, 'ambiguous_write_blocks_second_document' => true),
    'reorg-recompletion' => array('full_documents' => 1, 'over_notes' => 1, 'stock_effect_count' => 1,
        'notification_attempts' => 1, 'old_callback_preserves_new_full' => true, 'rewound_cash_review_visible' => true),
    'native-mail' => array('credit_slip_suppressed_inside_finalizer' => true, 'unrelated_mail_works' => true,
        'started_committed_before_send' => true, 'lost_ack_state' => 'unknown', 'automatic_resends' => 0),
    'shipping-tax-upgrade' => array('full_draft_shipping_tax_match' => true, 'native_hook_exact_id' => true,
        'install_upgrade_idempotent' => true, 'reinstall_keeps_refund_identity' => true),
);

if (getenv('PAYMOS_REFUND_NATIVE_STAND') !== '1' || $argc !== 2) {
    foreach (array('1.7.8.11', '8.2.1', '9.0.0') as $version) {
        foreach ($scenarios as $scenario => $expected) {
            echo 'SKIP PrestaShop ' . $version . ' ' . $scenario . ': approved native stand/package/driver unavailable' . PHP_EOL;
        }
    }
    echo 'RESULT passed=0 failed=0 skipped=21' . PHP_EOL;
    exit(0);
}

$driver = require $argv[1];
if (!($driver instanceof PrestaShopRefundStandDriver)) { throw new RuntimeException('Native fixture driver contract missing.'); }
$metadata = $driver->metadata();
$root = rtrim($metadata['shop_root'] ?? '', '/\\');
if (!defined('_PS_VERSION_') || ($metadata['version'] ?? '') !== _PS_VERSION_
    || !is_file($root . '/config/config.inc.php') || !is_file($root . '/classes/order/Order.php')
    || !class_exists('OrderCore') || realpath((new ReflectionClass('OrderCore'))->getFileName()) !== realpath($root . '/classes/order/Order.php')
    || empty($metadata['paymos_revision']) || ($metadata['same_master_connection'] ?? false) !== true
    || ($metadata['transactional_tables'] ?? false) !== true) {
    throw new RuntimeException('Actual native package/version/connection evidence missing; stubs are not native proof.');
}
echo json_encode($metadata, JSON_UNESCAPED_SLASHES) . PHP_EOL;
$passed = 0;
$failed = 0;
foreach ($scenarios as $scenario => $expected) {
    try {
        $actual = $driver->runScenario($scenario);
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $actual) || $actual[$key] !== $value) { throw new RuntimeException('Native oracle mismatch: ' . $key); }
        }
        if (empty($actual['evidence']) || !is_array($actual['evidence'])) { throw new RuntimeException('Native evidence paths missing.'); }
        foreach ($actual['evidence'] as $path) {
            if (!is_string($path) || !is_file($path)) { throw new RuntimeException('Native evidence file missing.'); }
        }
        echo 'PASS ' . _PS_VERSION_ . ' ' . $scenario . PHP_EOL;
        $passed++;
    } catch (Throwable $error) {
        echo 'FAIL ' . _PS_VERSION_ . ' ' . $scenario . ': ' . $error->getMessage() . PHP_EOL;
        $failed++;
    }
}
echo 'RESULT passed=' . $passed . ' failed=' . $failed . ' skipped=0' . PHP_EOL;
exit($failed ? 1 : 0);
