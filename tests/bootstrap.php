<?php

declare(strict_types=1);

define('PAYMOS_PRESTASHOP_PLUGIN_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('PAYMOS_PRESTASHOP_MODULE_DIR', PAYMOS_PRESTASHOP_PLUGIN_DIR . 'paymos' . DIRECTORY_SEPARATOR);

spl_autoload_register(static function ($class) {
    $prefix = 'PaymosPrestaShop\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $relative = substr($class, strlen($prefix));
        $path = PAYMOS_PRESTASHOP_MODULE_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require $path;
        }

        return;
    }

    $sdkPrefix = 'Paymos\\';
    if (strncmp($class, $sdkPrefix, strlen($sdkPrefix)) === 0) {
        $relative = substr($class, strlen($sdkPrefix));
        $candidates = array(
            PAYMOS_PRESTASHOP_MODULE_DIR . 'vendor/paymos/php-sdk/src/' . str_replace('\\', '/', $relative) . '.php',
            getenv('PAYMOS_SDK_SRC')
                ? rtrim(getenv('PAYMOS_SDK_SRC'), '/\\') . '/' . str_replace('\\', '/', $relative) . '.php'
                : null,
            dirname(rtrim(PAYMOS_PRESTASHOP_PLUGIN_DIR, '/\\')) . '/php-sdk/src/' . str_replace('\\', '/', $relative) . '.php',
        );
        foreach ($candidates as $candidate) {
            if ($candidate !== null && is_file($candidate)) {
                require $candidate;

                return;
            }
        }
    }
});

function assertSameValue($expected, $actual, $message)
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertTrueValue($actual, $message)
{
    if ($actual !== true) {
        throw new RuntimeException($message . ' Expected true, got ' . var_export($actual, true));
    }
}

function assertFalseValue($actual, $message)
{
    if ($actual !== false) {
        throw new RuntimeException($message . ' Expected false, got ' . var_export($actual, true));
    }
}

function assertContainsValue($needle, $haystack, $message)
{
    if (strpos((string) $haystack, (string) $needle) === false) {
        throw new RuntimeException($message . ' Missing ' . var_export($needle, true) . ' in ' . var_export($haystack, true));
    }
}

/**
 * @return array<string, string>
 */
function prestashop_settings(array $overrides = array())
{
    return array_merge(array(
        'PAYMOS_MODE' => 'sandbox',
        'PAYMOS_API_BASE_URL' => 'https://api.paymos.test',
        'PAYMOS_SANDBOX_API_KEY' => 'pk_test_123',
        'PAYMOS_SANDBOX_API_SECRET' => 'sk_test_123',
        'PAYMOS_SANDBOX_PROJECT_ID' => 'prj_123',
        'PAYMOS_SANDBOX_WEBHOOK_SECRET' => 'whsec_sandbox',
        'PAYMOS_LIVE_API_KEY' => 'pk_live_123',
        'PAYMOS_LIVE_API_SECRET' => 'sk_live_123',
        'PAYMOS_LIVE_PROJECT_ID' => 'prj_live_123',
        'PAYMOS_LIVE_WEBHOOK_SECRET' => 'whsec_live',
    ), $overrides);
}

/**
 * @return array<string, mixed>
 */
function prestashop_order(array $overrides = array())
{
    return array_merge(array(
        'id_order' => 42,
        'id_cart' => 24,
        'id_customer' => 77,
        'total' => '100.00',
        'currency' => 'USD',
        'current_state' => 1,
        'reference' => 'ABCDEFGHI',
    ), $overrides);
}

function prestashop_signed_header($secret, $body, $timestamp)
{
    return 't=' . (int) $timestamp . ',v1=' . hash_hmac('sha256', (string) $timestamp . '.' . (string) $body, (string) $secret);
}

/**
 * @return array<string, mixed>
 */
function prestashop_invoice_event($eventId, $eventType, $status, array $overrides = array())
{
    return array_replace_recursive(array(
        'event_id' => $eventId,
        'event_type' => $eventType,
        'occurred_at' => 1709000000,
        'data' => array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => $status,
            'is_test' => true,
            'order' => array(
                'external_id' => 'ps_42_0',
                'amount' => '100.00',
                'currency' => 'USD',
            ),
        ),
    ), $overrides);
}

/**
 * @return array<string, mixed>
 */
function prestashop_snapshot(array $overrides = array())
{
    return array_merge(array(
        'id_order' => 42,
        'id_cart' => 24,
        'paymos_invoice_id' => 'inv_123',
        'external_order_id' => 'ps_42_0',
        'environment' => 'sandbox',
        'project_id' => 'prj_123',
        'amount' => '100.00',
        'currency' => 'USD',
        'payment_url' => 'https://checkout.paymos.test/inv_123',
        'status' => 'created',
        'renew_count' => 0,
    ), $overrides);
}

function paymos_prestashop_reset_test_state()
{
    $config = PAYMOS_PRESTASHOP_MODULE_DIR . 'paymos-config.php';
    if (is_file($config)) {
        unlink($config);
    }

    if (class_exists('PaymosPrestaShop\\Config') && method_exists('PaymosPrestaShop\\Config', 'resetForTests')) {
        PaymosPrestaShop\Config::resetForTests();
    }
}

function paymos_prestashop_write_generated_config($php)
{
    file_put_contents(PAYMOS_PRESTASHOP_MODULE_DIR . 'paymos-config.php', "<?php\n\nreturn " . $php . ";\n");

    if (class_exists('PaymosPrestaShop\\Config') && method_exists('PaymosPrestaShop\\Config', 'resetForTests')) {
        PaymosPrestaShop\Config::resetForTests();
    }
}

/**
 * Fake PrestaShop adapter — records every order mutation for assertions.
 */
final class FakePrestaShopAdapter implements PaymosPrestaShop\PrestaShopAdapterInterface
{
    /** @var array<int, array<string, mixed>> */
    public $orders = array();

    /** @var array<int, array<string, mixed>> */
    public $transitions = array();

    /** @var array<int, array<string, mixed>> */
    public $notes = array();

    /** @var array<int, array<string, mixed>> */
    public $logs = array();

    /** @var array<int, bool> */
    public $paid = array();

    /** @var array<string, int> */
    public $stateIds = array(
        'pending' => 1,
        'confirming' => 2,
        'paid' => 5,
        'failed' => 8,
        'cancelled' => 6,
        'manual_review' => 9,
    );

    public function __construct()
    {
        $this->orders[42] = prestashop_order();
    }

    public function getOrder($orderId)
    {
        $orderId = (int) $orderId;

        return isset($this->orders[$orderId]) ? $this->orders[$orderId] : array();
    }

    public function orderHasBeenPaid($orderId)
    {
        return isset($this->paid[(int) $orderId]) ? (bool) $this->paid[(int) $orderId] : false;
    }

    public function setOrderState($orderId, $orderStateId)
    {
        $this->transitions[] = array(
            'id_order' => (int) $orderId,
            'id_order_state' => (int) $orderStateId,
        );
    }

    public function orderStateId($actionKey)
    {
        return isset($this->stateIds[$actionKey]) ? (int) $this->stateIds[$actionKey] : 1;
    }

    public function addOrderNote($orderId, $note)
    {
        $this->notes[] = array(
            'id_order' => (int) $orderId,
            'note' => (string) $note,
        );
    }

    public function log($message, array $context = array())
    {
        $this->logs[] = array(
            'message' => (string) $message,
            'context' => $context,
        );
    }
}

/**
 * Tiny in-memory DbInterface with a real unique-key constraint on event_id, so
 * EventStore dedup is exercised exactly as in production.
 */
final class FakeDb implements PaymosPrestaShop\DbInterface
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private $tables = array();

    public function execute($sql)
    {
        // The stores only issue DELETE/UPDATE here; the tests assert through
        // higher-level store reads, so a structural no-op is sufficient for the
        // unit-level event-store path which goes through insert(). The patterns
        // are whitespace/newline tolerant because the real SQL spans lines.
        $sql = (string) $sql;

        if (preg_match('/DELETE\s+FROM\s+`([^`]+)`\s+WHERE\s+`expires_at`\s*<\s*(\d+)/is', $sql, $m)) {
            $table = $m[1];
            $cutoff = (int) $m[2];
            if (isset($this->tables[$table])) {
                foreach ($this->tables[$table] as $key => $row) {
                    if (isset($row['expires_at']) && (int) $row['expires_at'] < $cutoff) {
                        unset($this->tables[$table][$key]);
                    }
                }
            }

            return true;
        }

        if (preg_match('/DELETE\s+FROM\s+`([^`]+)`\s+WHERE\s+`event_id`\s*=\s*\'([^\']*)\'/is', $sql, $m)) {
            $table = $m[1];
            if (isset($this->tables[$table][$m[2]])) {
                unset($this->tables[$table][$m[2]]);
            }

            return true;
        }

        if (preg_match('/UPDATE\s+`([^`]+)`\s+SET\s+`expires_at`\s*=\s*(\d+)\s+WHERE\s+`event_id`\s*=\s*\'([^\']*)\'/is', $sql, $m)) {
            $table = $m[1];
            if (isset($this->tables[$table][$m[3]])) {
                $this->tables[$table][$m[3]]['expires_at'] = (int) $m[2];
            }

            return true;
        }

        return true;
    }

    public function getValue($sql)
    {
        return '';
    }

    public function getRow($sql)
    {
        return null;
    }

    public function getRows($sql)
    {
        return array();
    }

    public function insert($table, array $row)
    {
        if (!isset($this->tables[$table])) {
            $this->tables[$table] = array();
        }

        // event_id is the PRIMARY KEY — duplicate insert returns false, exactly
        // like a MySQL unique-key collision.
        if (isset($row['event_id'])) {
            $key = (string) $row['event_id'];
            if (isset($this->tables[$table][$key])) {
                return false;
            }
            $this->tables[$table][$key] = $row;

            return true;
        }

        $this->tables[$table][] = $row;

        return true;
    }

    public function escape($value)
    {
        return addslashes((string) $value);
    }
}
