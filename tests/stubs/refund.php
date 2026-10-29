<?php

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\CommandBus {
    interface CommandBusInterface
    {
        public function handle($command);
    }
}

namespace PrestaShop\PrestaShop\Core\Domain\Order {
    class VoucherRefundType
    {
        const PRODUCT_PRICES_REFUND = 0;
    }
}

namespace {
    class Configuration
    {
        public static $values = array();
        public static function get($key) { return self::$values[$key] ?? false; }
        public static function updateValue($key, $value) { self::$values[$key] = $value; return true; }
    }

    class Tools
    {
        public static $values = array();
        public static function getValue($key, $default = false) { return self::$values[$key] ?? $default; }
        public static function getAdminTokenLite($controller) { return 'admin-csrf-' . $controller; }
    }

    class Profile
    {
        public static $access = array('view' => '1', 'edit' => '1');
        public static function getProfileAccess($profile, $tab) { return self::$access; }
    }

    class Tab
    {
        public static function getIdFromClassName($name) { return 1; }
    }

    class Shop
    {
        const CONTEXT_SHOP = 1;
        public static $context = 1;
        public static function getContext() { return self::$context; }
    }

    class Order
    {
        public $id;
        public $id_shop = 1;
        public $module = 'paymos';
        public static $shopId = 1;
        public function __construct($id) { $this->id = $id; $this->id_shop = self::$shopId; }
    }

    class Validate
    {
        public static function isLoadedObject($object) { return (int) $object->id > 0; }
    }
}
