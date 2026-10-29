<?php

declare(strict_types=1);

namespace PaymosPrestaShop;

use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\NativeProjectionResult;
use Paymos\Plugin\RefundIntent;
use Paymos\Plugin\VerifiedRefund;
use PrestaShop\PrestaShop\Core\Domain\Order\Command\IssuePartialRefundCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\Command\IssueStandardRefundCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\VoucherRefundType;

/** Only this class calls native accounting and notification services. */
final class RefundNativePlatform
{
    private $store;
    private $container;
    private static $context;

    public function __construct(RefundIntentStore $store) { $this->store = $store; }

    public function assertReady(bool $financial = true): void
    {
        if (version_compare(_PS_VERSION_, '1.7.8.0', '<') || version_compare(_PS_VERSION_, '10.0.0', '>=')) {
            throw new \RuntimeException('refund_native_version_unsupported');
        }
        $container = $this->container ?: \PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance();
        if ($financial && ($container === null || !$container->has('paymos.refund_command_bus'))) {
            throw new \RuntimeException('refund_native_command_binding_missing');
        }
        $module = \Module::getInstanceByName('paymos');
        foreach ($financial ? array('actionOrderSlipAdd', 'actionEmailSendBefore') : array() as $hook) {
            if (!$module || !$module->isRegisteredInHook($hook)) { throw new \RuntimeException('refund_native_hook_missing'); }
        }
        $tables = array('orders', 'message', 'paymos_invoice', 'paymos_refund_installation', 'paymos_refund_intent',
            'paymos_refund_projection', 'paymos_refund_unbound');
        if ($financial) {
            $tables = array_merge($tables, array('order_detail', 'order_detail_tax', 'order_carrier', 'order_slip', 'order_slip_detail',
                'order_payment', 'order_invoice', 'order_invoice_payment', 'stock', 'stock_available', 'stock_mvt'));
        }
        foreach ($tables as $table) {
            $rows = $this->store->database()->getRows('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '
                . $this->store->quote(Migrations::table($table)));
            if (count($rows) !== 1 || strcasecmp($rows[0]['ENGINE'], 'InnoDB') !== 0) {
                throw new \RuntimeException('refund_requires_innodb');
            }
        }
    }

    /** Callbacks and CLI need the same admin services as the saved native command. */
    public function prepare(RefundIntent $intent): void
    {
        if ($intent->mode() !== 'full') { return; }
        $container = \PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance();
        if ($container !== null && $container->has('paymos.refund_command_bus')) {
            $this->container = $container;
            return;
        }
        $directory = $intent->fullDraft()['admin_dir'] ?? null;
        $root = realpath(_PS_ROOT_DIR_);
        $directory = is_string($directory) ? realpath($directory) : false;
        if ($directory === false || dirname($directory) !== $root || !is_file($directory . '/index.php')
            || (defined('_PS_ADMIN_DIR_') && realpath(_PS_ADMIN_DIR_) !== $directory)) {
            throw new \RuntimeException('refund_admin_services_unavailable');
        }
        if (!defined('_PS_ADMIN_DIR_')) { define('_PS_ADMIN_DIR_', $directory); }
        global $kernel;
        $kernelClass = version_compare(_PS_VERSION_, '9.0.0', '>=') ? 'AdminKernel' : 'AppKernel';
        if (!class_exists($kernelClass)) { require_once _PS_ROOT_DIR_ . '/app/' . $kernelClass . '.php'; }
        $kernel = new $kernelClass('prod', false);
        $kernel->boot();
        $this->container = $kernel->getContainer();
        if (!$this->container->has('paymos.refund_command_bus')) { throw new \RuntimeException('refund_native_command_binding_missing'); }
    }

    public function invoiceMapping(string $invoiceId): array
    {
        $rows = $this->store->database()->getRows('SELECT * FROM `' . Migrations::table('paymos_invoice')
            . '` WHERE `paymos_invoice_id` = ' . $this->store->quote($invoiceId));
        if (count($rows) !== 1 || (int) $rows[0]['id_order'] <= 0) { throw new \RuntimeException('refund_invoice_mapping_ambiguous'); }
        return $rows[0];
    }

    public function snapshot(int $orderId): array
    {
        $db = $this->store->database();
        $rows = $db->getRows('SELECT * FROM `' . Migrations::table('orders') . '` WHERE `id_order` = ' . $orderId
            . ($this->store->inTransaction() ? ' FOR UPDATE' : ''));
        if (count($rows) !== 1 || $rows[0]['module'] !== 'paymos') { throw new \RuntimeException('refund_order_mapping_invalid'); }
        $order = $rows[0];
        $mapping = $db->getRows('SELECT * FROM `' . Migrations::table('paymos_invoice') . '` WHERE `id_order` = ' . $orderId);
        if (count($mapping) !== 1 || !in_array($mapping[0]['status'], array('paid', 'paid_over'), true)) {
            throw new \RuntimeException('refund_invoice_mapping_ambiguous');
        }
        $mapping = $mapping[0];
        $payments = $db->getRows('SELECT p.* FROM `' . Migrations::table('order_payment') . '` p WHERE p.order_reference = '
            . $this->store->quote($order['reference']) . ' ORDER BY p.id_order_payment');
        $siblings = $db->getRows('SELECT id_order FROM `' . Migrations::table('orders') . '` WHERE reference = ' . $this->store->quote($order['reference']));
        $currency = $db->getRow('SELECT * FROM `' . Migrations::table('currency') . '` WHERE id_currency = ' . (int) $order['id_currency']);
        if (count($payments) !== 1 || count($siblings) !== 1 || $currency === null
            || (int) $payments[0]['id_currency'] !== (int) $order['id_currency']
            || !AmountGuard::isSafeToComplete($mapping['amount'], $mapping['currency'], $order['total_paid_tax_incl'], $currency['iso_code'],
                $payments[0]['amount'], $currency['iso_code'])) {
            throw new \RuntimeException('refund_native_payment_not_full');
        }
        $details = $db->getRows('SELECT id_order_detail,product_quantity,product_quantity_refunded,product_quantity_return,
            product_quantity_reinjected,total_price_tax_incl,total_price_tax_excl FROM `' . Migrations::table('order_detail')
            . '` WHERE id_order = ' . $orderId . ' ORDER BY id_order_detail');
        if (!$details) { throw new \RuntimeException('refund_native_draft_empty'); }
        $refunded = count($this->slips($orderId)) > 0;
        foreach ($details as $detail) {
            if ((int) $detail['product_quantity_refunded'] > 0 || (int) $detail['product_quantity_return'] > 0
                || (int) $detail['product_quantity_reinjected'] > 0) { $refunded = true; }
        }
        $nativeOrder = new \Order($orderId);
        $customer = new \Customer((int) $order['id_customer']);
        $taxIncluded = \Group::getPriceDisplayMethod((int) $customer->id_default_group) === PS_TAX_INC;
        return array('id_order' => $orderId, 'id_shop' => (int) $order['id_shop'],
            'payment_id' => (string) $payments[0]['id_order_payment'], 'invoice_id' => $mapping['paymos_invoice_id'],
            'external_order_id' => $mapping['external_order_id'], 'project_id' => $mapping['project_id'], 'environment' => $mapping['environment'],
            'paid' => (bool) $nativeOrder->hasBeenPaid(), 'delivered' => (bool) $nativeOrder->hasBeenDelivered(), 'refunded' => $refunded,
            'returns_enabled' => (bool) \Configuration::get('PS_ORDER_RETURN'),
            'amount_minor' => self::minor($order['total_paid_tax_incl'], (int) $currency['precision']),
            'currency' => $currency['iso_code'], 'precision' => (int) $currency['precision'],
            'shipping' => (string) $order[$taxIncluded ? 'total_shipping_tax_incl' : 'total_shipping_tax_excl'],
            'tax_included' => $taxIncluded, 'discounts' => (string) $order['total_discounts_tax_incl'],
            'details' => $details, 'version' => _PS_VERSION_);
    }

    public function fullDraft(array $snapshot): array
    {
        $standard = !$snapshot['delivered'] && $snapshot['returns_enabled'];
        $items = array();
        foreach ($snapshot['details'] as $detail) {
            $items[(int) $detail['id_order_detail']] = array('quantity' => (int) $detail['product_quantity']);
            if (!$standard) {
                $items[(int) $detail['id_order_detail']]['amount'] = $detail[$snapshot['tax_included'] ? 'total_price_tax_incl' : 'total_price_tax_excl'];
            }
        }
        return array('amount_minor' => $snapshot['amount_minor'], 'currency' => $snapshot['currency'],
            'command' => $standard ? 'standard' : 'partial', 'items' => $items,
            'shipping' => $snapshot['shipping'], 'credit_slip' => true, 'voucher' => false,
            'voucher_type' => VoucherRefundType::PRODUCT_PRICES_REFUND,
            'restock' => $standard, 'admin_dir' => defined('_PS_ADMIN_DIR_') ? realpath(_PS_ADMIN_DIR_) : null);
    }

    public function validDraft(array $snapshot, ?array $draft): bool
    {
        foreach (array('amount_minor', 'currency', 'command', 'items', 'shipping', 'credit_slip', 'voucher', 'voucher_type', 'restock') as $field) {
            if ($draft === null || !array_key_exists($field, $draft)) { return false; }
        }
        if (!is_array($draft['items']) || !is_bool($draft['restock'])
            || $draft['voucher_type'] !== VoucherRefundType::PRODUCT_PRICES_REFUND
            || $draft['amount_minor'] !== $snapshot['amount_minor'] || $draft['currency'] !== $snapshot['currency']
            || $draft['credit_slip'] !== true || $draft['voucher'] !== false || !in_array($draft['command'], array('standard', 'partial'), true)
            || !AmountGuard::amountsEqual($draft['shipping'], $snapshot['shipping'])
            || !AmountGuard::amountsEqual($snapshot['discounts'], '0')) { return false; }
        if ($draft['command'] === 'standard' && ($snapshot['delivered'] || !$snapshot['returns_enabled'] || !$draft['restock'])) { return false; }
        $expected = array();
        foreach ($snapshot['details'] as $detail) {
            $expected[(int) $detail['id_order_detail']] = true;
            $item = $draft['items'][(int) $detail['id_order_detail']] ?? null;
            if (!is_array($item) || ($item['quantity'] ?? null) !== (int) $detail['product_quantity']
                || ($draft['command'] === 'partial' && !AmountGuard::amountsEqual($item['amount'] ?? null,
                    $detail[$snapshot['tax_included'] ? 'total_price_tax_incl' : 'total_price_tax_excl']))) { return false; }
        }
        return count($expected) === count($draft['items']);
    }

    /** Extract only a full native command; offline/manual calls never create provider cash. */
    public function commandDraft($command, array $snapshot): array
    {
        $draft = $this->fullDraft($snapshot);
        $draft['command'] = $command instanceof IssueStandardRefundCommand ? 'standard' : 'partial';
        $draft['items'] = array();
        foreach ($command->getOrderDetailRefunds() as $detail) {
            $item = array('quantity' => $detail->getProductQuantity());
            if ($draft['command'] === 'partial') { $item['amount'] = (string) $detail->getRefundedAmount(); }
            $draft['items'][$detail->getOrderDetailId()] = $item;
        }
        $draft['shipping'] = $draft['command'] === 'standard'
            ? ($command->refundShippingCost() ? $snapshot['shipping'] : '0') : (string) $command->getShippingCostRefundAmount();
        $draft['credit_slip'] = $command->generateCreditSlip();
        $draft['voucher'] = $command->generateVoucher();
        $draft['voucher_type'] = $command->getVoucherRefundType();
        $draft['restock'] = $command->restockRefundedProducts();
        if (!$this->validDraft($snapshot, $draft)) { throw new \RuntimeException('refund_partial_or_changed_draft'); }
        return $draft;
    }

    public function applyFull(RefundIntent $intent): array
    {
        $snapshot = $this->snapshot((int) $intent->toArray()['native_identity']['id_order']);
        if ($snapshot !== $intent->toArray()['native_identity'] || !$this->validDraft($snapshot, $intent->fullDraft())) {
            throw new \RuntimeException('refund_native_payment_changed');
        }
        if (self::$context !== null) { throw new \RuntimeException('refund_native_nested_finalizer'); }
        $draft = $intent->fullDraft();
        $orderId = $snapshot['id_order'];
        $command = $draft['command'] === 'standard'
            ? new IssueStandardRefundCommand($orderId, $draft['items'], true, true, false, $draft['voucher_type'])
            : new IssuePartialRefundCommand($orderId, $draft['items'], $draft['shipping'], $draft['restock'], true, false, $draft['voucher_type']);
        self::$context = array('platform' => $this, 'intent' => $intent, 'command' => $command,
            'before' => $this->slips($orderId), 'id' => null, 'mail' => null);
        try {
            $container = $this->container ?: \PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance();
            $container->get('prestashop.core.command_bus')->handle($command);
            $id = self::$context['id'];
            if ($id === null || !$this->slipMatches($intent, $id)) { throw new \RuntimeException('refund_native_slip_ambiguous'); }
            if (self::$context['mail'] === null) { throw new \RuntimeException('refund_native_mail_not_intercepted'); }
            return array('id' => $id, 'mail' => self::$context['mail']);
        } finally { self::$context = null; }
    }

    public static function ownsCommand($command): bool
    {
        return self::$context !== null && self::$context['command'] === $command;
    }

    public static function captureSlip(array $params): void
    {
        if (self::$context === null) { return; }
        $intent = self::$context['intent'];
        $orderId = $intent->toArray()['native_identity']['id_order'];
        if (!isset($params['order']) || (int) $params['order']->id !== $orderId) { return; }
        $platform = self::$context['platform'];
        $created = array_diff_key($platform->slips($orderId), self::$context['before']);
        if (count($created) !== 1) { throw new \RuntimeException('refund_native_slip_ambiguous'); }
        $id = (string) key($created);
        if (version_compare(_PS_VERSION_, '9.0.0', '>=')) {
            if (!isset($params['orderSlipCreated']) || !($params['orderSlipCreated'] instanceof \OrderSlip)
                || (string) $params['orderSlipCreated']->id !== $id) { throw new \RuntimeException('refund_native_slip_hook_invalid'); }
        }
        if (!$platform->slipMatches($intent, $id)) { throw new \RuntimeException('refund_native_slip_conflict'); }
        self::$context['id'] = $id;
    }

    public static function captureMail(array $params): bool
    {
        if (self::$context === null || ($params['template'] ?? '') !== 'credit_slip') { return true; }
        $identity = self::$context['intent']->toArray()['native_identity'];
        if ((int) ($params['templateVars']['{id_order}'] ?? 0) !== $identity['id_order']
            || (int) ($params['idShop'] ?? 0) !== $identity['id_shop']) { return true; }
        $saved = array();
        foreach (array('idLang', 'template', 'subject', 'templateVars', 'to', 'toName', 'from', 'fromName', 'fileAttachment',
            'mode_smtp', 'templatePath', 'die', 'idShop', 'bcc', 'replyTo', 'replyToName') as $field) {
            $saved[$field] = $params[$field] ?? null;
        }
        if (self::$context['mail'] !== null) { throw new \RuntimeException('refund_native_multiple_mail'); }
        self::$context['mail'] = $saved;
        return false;
    }

    public function sendMail(array $mail): bool
    {
        $mail['die'] = false;
        $result = call_user_func_array(array('Mail', 'Send'), array_values($mail));
        return $result === true || (is_int($result) && $result > 0);
    }

    public function cashNote(int $orderId, VerifiedRefund $refund): string
    {
        $order = new \Order($orderId);
        $message = new \Message();
        $message->id_order = $orderId;
        $message->id_cart = (int) $order->id_cart;
        $message->id_customer = (int) $order->id_customer;
        $message->private = 1;
        $message->message = 'Paymos overpayment refund completed. The order stays paid and stock is unchanged. Refund ID ' . $refund->withdrawalId() . ', amount ' . $refund->principalDecimal()
            . ' ' . $refund->currency() . '/' . $refund->network();
        if (!$message->add() || !(int) $message->id) { throw new \RuntimeException('refund_cash_note_failed'); }
        return (string) $message->id;
    }

    public function matchesMarker(RefundIntent $intent, NativeProjectionResult $result): bool
    {
        if ($intent->mode() === 'full') { return $this->slipMatches($intent, (string) $result->nativeId()); }
        $row = $this->store->database()->getRow('SELECT * FROM `' . Migrations::table('message')
            . '` WHERE id_message = ' . (int) $result->nativeId());
        return $row !== null && (int) $row['id_order'] === $intent->toArray()['native_identity']['id_order']
            && (int) $row['private'] === 1 && strpos($row['message'], (string) $intent->withdrawalId()) !== false;
    }

    private function slips(int $orderId): array
    {
        $result = array();
        foreach ($this->store->database()->getRows('SELECT *, (total_products_tax_incl + total_shipping_tax_incl) AS native_total FROM `'
            . Migrations::table('order_slip') . '` WHERE id_order = ' . $orderId . ' ORDER BY id_order_slip') as $row) {
            $result[$row['id_order_slip']] = $row;
        }
        return $result;
    }

    private function slipMatches(RefundIntent $intent, string $id): bool
    {
        $snapshot = $intent->toArray()['native_identity'];
        $current = $this->snapshot($snapshot['id_order']);
        foreach (array('payment_id', 'invoice_id', 'project_id', 'environment', 'amount_minor', 'currency') as $field) {
            if ($current[$field] !== $snapshot[$field]) { return false; }
        }
        $slips = $this->slips($snapshot['id_order']);
        if (count($slips) !== 1 || !isset($slips[$id])) { return false; }
        return self::minor($slips[$id]['native_total'], $snapshot['precision']) === $intent->fullDraft()['amount_minor'];
    }

    /** Exact native decimal conversion; no crypto/native exchange calculation. */
    public static function minor(string $amount, int $precision): string
    {
        $value = AmountGuard::normalizeDecimal($amount);
        if ($value === null || $value === '0' || $value[0] === '-' || $precision < 0 || $precision > 8) {
            throw new \RuntimeException('refund_native_amount_invalid');
        }
        $parts = explode('.', $value);
        $fraction = $parts[1] ?? '';
        if (strlen($fraction) > $precision) { throw new \RuntimeException('refund_native_amount_precision'); }
        return ltrim($parts[0] . str_pad($fraction, $precision, '0'), '0');
    }

    public function log(string $message): void { \PrestaShopLogger::addLog('[Paymos] ' . $message, 3); }
}
