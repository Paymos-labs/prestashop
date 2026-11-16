<?php

declare(strict_types=1);

namespace PaymosPrestaShop;

use Paymos\Plugin\InvoicePresentation;

/**
 * PrestaShopAdapterInterface implementation over real PrestaShop objects
 * (\Order, \OrderHistory, \Configuration, \PrestaShopLogger). Runtime-only — the
 * unit tests inject a fake adapter instead.
 *
 * Order-state ids for the Paymos-specific states (pending / confirming /
 * manual_review) are persisted in PrestaShop Configuration at module install
 * under PAYMOS_OS_* keys. paid/failed/cancelled map to PrestaShop core states
 * (PS_OS_PAYMENT / PS_OS_ERROR / PS_OS_CANCELED) so they stay consistent with the
 * rest of the shop.
 */
final class PrestaShopAdapter implements PrestaShopAdapterInterface
{
    private $orderLock = '';

    public function withOrderLock($orderId, callable $operation)
    {
        $db = \Db::getInstance();
        $name = 'paymos_ps_' . substr(hash('sha256', _DB_PREFIX_ . ':' . (int) $orderId), 0, 48);
        if ($this->orderLock !== '') {
            throw new \RuntimeException('Nested Paymos order lock is not supported.');
        }
        if ((string) $db->getValue("SELECT GET_LOCK('" . pSQL($name) . "', 0)", false) !== '1') {
            throw new \Paymos\Exception\EventInProgressException('Paymos PrestaShop order is busy.');
        }
        $this->orderLock = $name;
        try {
            return $operation();
        } finally {
            $this->orderLock = '';
            $db->getValue("SELECT RELEASE_LOCK('" . pSQL($name) . "')", false);
        }
    }

    public function assertOrderLockOwned()
    {
        if ($this->orderLock !== '' && (string) \Db::getInstance()->getValue(
            "SELECT IS_USED_LOCK('" . pSQL($this->orderLock) . "') = CONNECTION_ID()", false
        ) !== '1') {
            throw new \RuntimeException('Paymos PrestaShop order lock was lost.');
        }
    }

    /** Logical action key → PrestaShop Configuration key holding the state id. */
    private const STATE_KEYS = array(
        'pending' => 'PAYMOS_OS_PENDING',
        'confirming' => 'PAYMOS_OS_CONFIRMING',
        'manual_review' => 'PAYMOS_OS_MANUAL_REVIEW',
        'paid' => 'PS_OS_PAYMENT',
        'failed' => 'PS_OS_ERROR',
        'cancelled' => 'PS_OS_CANCELED',
    );

    public function getOrder($orderId)
    {
        $order = new \Order((int) $orderId);
        if (!\Validate::isLoadedObject($order)) {
            return array();
        }

        $currency = class_exists('\\Currency') ? new \Currency((int) $order->id_currency) : null;
        $currencyIso = is_object($currency) && \Validate::isLoadedObject($currency) ? (string) $currency->iso_code : '';
        $precision = is_object($currency) && \Validate::isLoadedObject($currency) && isset($currency->precision)
            ? max(0, (int) $currency->precision)
            : 2;

        $result = array(
            'id_order' => (int) $order->id,
            'id_cart' => (int) $order->id_cart,
            'id_customer' => (int) $order->id_customer,
            'total' => (string) $order->total_paid,
            'currency' => $currencyIso,
            'current_state' => (int) $order->current_state,
            'reference' => (string) $order->reference,
        );

        if (method_exists($order, 'getProducts')) {
            $products = $this->extractOrderProducts($order, $precision);
            if (count($products) > 0) {
                $result['products'] = $products;
            }
        }

        return $result;
    }

    /**
     * Extract native OrderDetail rows in the order currency without breaking
     * minimal test stubs where Product/Image/Link classes are not loaded.
     *
     * @return array<int, mixed>
     */
    private function extractOrderProducts(\Order $order, int $precision): array
    {
        $rows = $order->getProducts();
        if (!is_array($rows) || count($rows) === 0) {
            return array();
        }

        if (count($rows) > InvoicePresentation::MAX_ITEMS) {
            return array_fill(0, count($rows), array(
                'title' => 'Item',
                'quantity' => 1,
                'unit_amount' => '1.00',
            ));
        }

        $customerId = isset($order->id_customer) ? (int) $order->id_customer : 0;
        $taxIncluded = $this->usesTaxIncludedDisplay($customerId);
        $idLang = isset($order->id_lang) ? (int) $order->id_lang : 0;
        $idShop = isset($order->id_shop) ? (int) $order->id_shop : 0;
        $productCache = array();
        $products = array();

        foreach ($rows as $row) {
            if (!is_array($row)) {
                $products[] = $row;
                continue;
            }

            $productId = isset($row['product_id']) && is_numeric($row['product_id'])
                ? (int) $row['product_id']
                : (isset($row['id_product']) && is_numeric($row['id_product']) ? (int) $row['id_product'] : 0);
            $productAttributeId = isset($row['product_attribute_id']) && is_numeric($row['product_attribute_id'])
                ? (int) $row['product_attribute_id']
                : (isset($row['id_product_attribute']) && is_numeric($row['id_product_attribute']) ? (int) $row['id_product_attribute'] : 0);

            $title = isset($row['product_name']) && is_string($row['product_name'])
                ? $row['product_name']
                : (isset($row['name']) && is_string($row['name']) ? $row['name'] : null);
            $quantity = array_key_exists('product_quantity', $row)
                ? $row['product_quantity']
                : (array_key_exists('quantity', $row) ? $row['quantity'] : null);

            $priceKey = $taxIncluded ? 'unit_price_tax_incl' : 'unit_price_tax_excl';
            if (array_key_exists($priceKey, $row)) {
                $rawUnit = $row[$priceKey];
            } elseif (array_key_exists('unit_price_tax_incl', $row)) {
                $rawUnit = $row['unit_price_tax_incl'];
            } elseif (array_key_exists('product_price', $row)) {
                $rawUnit = $row['product_price'];
            } else {
                $rawUnit = null;
            }

            $productObj = $this->loadProduct($productId, $idLang, $idShop, $productCache);
            $description = $this->extractProductDescription($productObj, $idLang);
            $imageUrl = $this->extractProductImageUrl($row, $productObj, $productId, $productAttributeId, $idLang);

            $products[] = array(
                'title' => $title,
                'description' => $description,
                'image_url' => $imageUrl,
                'quantity' => $quantity,
                'unit_amount' => self::formatUnitAmount($rawUnit, $precision),
            );
        }

        return $products;
    }

    private function usesTaxIncludedDisplay(int $customerId): bool
    {
        if ($customerId > 0 && class_exists('\\Customer') && class_exists('\\Group') && defined('PS_TAX_INC')) {
            try {
                $customer = new \Customer($customerId);
                if (isset($customer->id_default_group)) {
                    return \Group::getPriceDisplayMethod((int) $customer->id_default_group) === PS_TAX_INC;
                }
            } catch (\Throwable $e) {
                return true;
            }
        }

        return true;
    }

    /**
     * @param array<int, object|null> $cache
     * @return object|null
     */
    private function loadProduct(int $productId, int $idLang, int $idShop, array &$cache)
    {
        if ($productId <= 0 || !class_exists('\\Product')) {
            return null;
        }
        if (array_key_exists($productId, $cache)) {
            return $cache[$productId];
        }

        try {
            $product = new \Product($productId, false, $idLang > 0 ? $idLang : null, $idShop > 0 ? $idShop : null);
            $cache[$productId] = \Validate::isLoadedObject($product) ? $product : null;
        } catch (\Throwable $e) {
            $cache[$productId] = null;
        }

        return $cache[$productId];
    }

    /**
     * @param object|null $productObj
     */
    private function extractProductDescription($productObj, int $idLang): ?string
    {
        if (!is_object($productObj)) {
            return null;
        }

        if (isset($productObj->description_short)) {
            $short = self::localizedString($productObj->description_short, $idLang);
            if ($short !== null && trim($short) !== '') {
                return $short;
            }
        }
        if (isset($productObj->description)) {
            $full = self::localizedString($productObj->description, $idLang);
            if ($full !== null && trim($full) !== '') {
                return $full;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @param object|null          $productObj
     */
    private function extractProductImageUrl(
        array $row,
        $productObj,
        int $productId,
        int $productAttributeId,
        int $idLang
    ): ?string {
        if ($productId <= 0) {
            return null;
        }

        $idImage = 0;
        if ($productAttributeId > 0 && class_exists('\\Product') && method_exists('\\Product', 'getCombinationImageById')) {
            try {
                $comb = \Product::getCombinationImageById($productAttributeId, $idLang);
                if (is_array($comb) && isset($comb['id_image']) && is_numeric($comb['id_image'])) {
                    $idImage = (int) $comb['id_image'];
                }
            } catch (\Throwable $e) {
                $idImage = 0;
            }
        }

        if ($idImage <= 0 && isset($row['id_image']) && is_numeric($row['id_image'])) {
            $idImage = (int) $row['id_image'];
        }
        if ($idImage <= 0 && isset($row['image']) && is_object($row['image']) && isset($row['image']->id) && is_numeric($row['image']->id)) {
            $idImage = (int) $row['image']->id;
        }
        if ($idImage <= 0 && class_exists('\\Image') && method_exists('\\Image', 'getCover')) {
            try {
                $cover = \Image::getCover($productId);
                if (is_array($cover) && isset($cover['id_image']) && is_numeric($cover['id_image'])) {
                    $idImage = (int) $cover['id_image'];
                }
            } catch (\Throwable $e) {
                $idImage = 0;
            }
        }

        if ($idImage <= 0 || !class_exists('\\Context')) {
            return null;
        }

        try {
            $context = \Context::getContext();
            $link = is_object($context) && isset($context->link) ? $context->link : null;
            if (!is_object($link) || !method_exists($link, 'getImageLink')) {
                return null;
            }

            $linkRewrite = is_object($productObj) && isset($productObj->link_rewrite)
                ? self::localizedString($productObj->link_rewrite, $idLang)
                : '';
            if ($linkRewrite === null || trim($linkRewrite) === '') {
                return null;
            }

            $imageType = class_exists('\\ImageType') && method_exists('\\ImageType', 'getFormattedName')
                ? \ImageType::getFormattedName('home')
                : 'home_default';

            $url = $link->getImageLink(trim($linkRewrite), $productId . '-' . $idImage, $imageType);
            return is_string($url) && trim($url) !== '' ? trim($url) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param mixed $value
     */
    private static function localizedString($value, int $idLang): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            if ($idLang > 0 && isset($value[$idLang]) && is_string($value[$idLang])) {
                return $value[$idLang];
            }
            foreach ($value as $entry) {
                if (is_string($entry) && trim($entry) !== '') {
                    return $entry;
                }
            }
        }

        return null;
    }

    /**
     * Format decimal string up to currency precision without rounding away
     * extra significant fractional digits.
     *
     * @param mixed $raw
     */
    public static function formatUnitAmount($raw, int $precision = 2): ?string
    {
        if (is_int($raw)) {
            return $precision > 0 ? ($raw . '.' . str_repeat('0', $precision)) : (string) $raw;
        }
        if (is_float($raw)) {
            if (!is_finite($raw)) {
                return null;
            }
            if ($raw < 0) {
                return '-1';
            }
            $str = (string) $raw;
            if (stripos($str, 'e') !== false) {
                $str = rtrim(rtrim(sprintf('%.18F', $raw), '0'), '.');
            }
        } elseif (is_string($raw)) {
            $str = trim($raw);
        } else {
            return null;
        }

        if ($str === '') {
            return null;
        }
        if (preg_match('/\A(0|[1-9][0-9]*)(?:\.([0-9]+))?\z/', $str, $m) !== 1) {
            return $str;
        }

        $intPart = $m[1];
        $fracPart = isset($m[2]) ? rtrim($m[2], '0') : '';
        if ($precision > 0 && strlen($fracPart) < $precision) {
            $fracPart = str_pad($fracPart, $precision, '0');
        }

        return $fracPart === '' ? $intPart : ($intPart . '.' . $fracPart);
    }

    public function orderHasBeenPaid($orderId)
    {
        $order = new \Order((int) $orderId);
        if (!\Validate::isLoadedObject($order)) {
            return false;
        }

        // Order::hasBeenPaid() checks the order-state history for any state whose
        // `paid` flag is set — the correct "already paid" signal for the
        // roll-back guard.
        return (bool) $order->hasBeenPaid();
    }

    public function setOrderState($orderId, $orderStateId)
    {
        $this->assertOrderLockOwned();
        $orderId = (int) $orderId;
        $orderStateId = (int) $orderStateId;
        if ($orderStateId <= 0) {
            throw new \RuntimeException('Paymos target order state is not configured.');
        }
        if (!\Validate::isLoadedObject(new \OrderState($orderStateId))) {
            throw new \RuntimeException('Paymos target order state was not found.');
        }

        $order = new \Order($orderId);
        if (!\Validate::isLoadedObject($order)) {
            throw new \RuntimeException('PrestaShop order was not found.');
        }

        // No-op if the order is already in the target state (avoids duplicate
        // history rows when the server re-delivers the same terminal event).
        $alreadyInState = (int) $order->getCurrentState() === $orderStateId;
        $lastHistoryState = (int) \Db::getInstance()->getValue('SELECT id_order_state FROM `' . _DB_PREFIX_
            . 'order_history` WHERE id_order = ' . $orderId . ' ORDER BY id_order_history DESC', false);
        if ($alreadyInState && $lastHistoryState === $orderStateId) {
            return;
        }

        $history = new \OrderHistory();
        $history->id_order = $orderId;
        $history->id_order_state = $orderStateId;
        // 2-arg form: do not reuse an existing payment — a hosted-checkout order
        // created in the awaiting state has no OrderPayment yet, so the paid
        // transition records one. addWithemail($autodate = true): always date the
        // history row; the buyer email is driven by the target OrderState's
        // send_email flag, not an argument here.
        if (!$alreadyInState) {
            $history->changeIdOrderState($orderStateId, $order);
        }
        if (!$history->addWithemail(true)) {
            throw new \RuntimeException('PrestaShop order history could not be saved.');
        }
        $savedOrder = new \Order($orderId);
        if (!\Validate::isLoadedObject($savedOrder) || (int) $savedOrder->getCurrentState() !== $orderStateId) {
            throw new \RuntimeException('PrestaShop order state was not persisted.');
        }
    }

    public function orderStateId($actionKey)
    {
        $key = isset(self::STATE_KEYS[$actionKey]) ? self::STATE_KEYS[$actionKey] : 'PAYMOS_OS_PENDING';
        $stateId = (int) \Configuration::get($key);
        if ($stateId > 0) {
            return $stateId;
        }

        // Manual-review has no core fallback; default it to the awaiting state so
        // a held order never silently lands in an unknown state.
        if ($actionKey === 'manual_review') {
            return (int) \Configuration::get('PAYMOS_OS_PENDING');
        }

        return $stateId;
    }

    public function addOrderNote($orderId, $note)
    {
        $this->assertOrderLockOwned();
        $order = new \Order((int) $orderId);
        if (!\Validate::isLoadedObject($order)) {
            throw new \RuntimeException('PrestaShop order for note was not found.');
        }

        $message = new \Message();
        $message->message = \Tools::substr((string) $note, 0, 1600);
        $message->id_order = (int) $order->id;
        $message->id_cart = (int) $order->id_cart;
        $message->id_customer = (int) $order->id_customer;
        $message->private = 1;
        if (!$message->add()) {
            throw new \RuntimeException('PrestaShop order note could not be saved.');
        }
    }

    public function log($message, array $context = array())
    {
        $line = '[Paymos] ' . (string) $message;
        if (count($context) > 0) {
            $line .= ' ' . json_encode($context);
        }

        // Severity 3 = error in PrestaShop's log levels.
        \PrestaShopLogger::addLog($line, 3, null, 'PaymosPrestaShop');
    }
}
