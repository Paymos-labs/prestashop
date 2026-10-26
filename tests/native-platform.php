<?php

declare(strict_types=1);

// CI ONLY: disposable official PrestaShop + MySQL, exact released module ZIP.
// No platform stubs, Paymos network calls, real credentials, payments or mail.
if (getenv('PAYMOS_NATIVE_CI') !== '1' || $argc !== 3) {
    fwrite(STDERR, "Disposable CI only: php native-platform.php SHOP_ROOT EXPECTED_VERSION\n");
    exit(2);
}
$_SERVER['HTTP_HOST'] = '127.0.0.1:8080';
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/index.php';
$nativeRoot = rtrim($argv[1], '/');
// PS9's installer finalizes bundle assets in admin-dev. Keep that native name.
define('_PS_ADMIN_DIR_', $nativeRoot . '/admin-dev');
require $nativeRoot . '/config/config.inc.php';

use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Http\HttpResponse;
use Paymos\Http\MockTransport;
use PaymosPrestaShop\CallbackProcessor;
use PaymosPrestaShop\EventStore;
use PaymosPrestaShop\GatewayCheckout;
use PaymosPrestaShop\InvoiceStore;
use PaymosPrestaShop\PrestaShopAdapter;
use PaymosPrestaShop\PrestaShopDb;

function check_native($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS ' . $message . "\n";
}

check_native(_PS_VERSION_ === $argv[2], 'Exact native PrestaShop version ' . _PS_VERSION_ . ' / PHP ' . PHP_VERSION);
$context = Context::getContext();
$context->employee = new Employee(1);
$context->currency = Currency::getDefaultCurrency();
// Follow the platform's own bin/console bootstrap. Module::install updates
// translation tables through SymfonyContainer; config.inc.php alone has no
// booted kernel. PrestaShop 9 split AppKernel into concrete app kernels.
global $kernel;
$kernel = class_exists('AdminKernel') ? new AdminKernel('prod', false) : new AppKernel('prod', false);
$kernel->boot();
check_native(\PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance() === $kernel->getContainer(),
    'Native module services use the booted platform kernel container');
Configuration::updateValue('PS_MAIL_METHOD', 3); // core setting: never send email
Configuration::updateValue('PS_SSL_ENABLED', 0); // isolated loopback HTTP only
Configuration::updateValue('PS_SSL_ENABLED_EVERYWHERE', 0);
Configuration::updateValue('PS_TAX', 0);
Configuration::updateValue('PS_SHOP_ENABLE', 1);
Configuration::updateValue('PS_REWRITING_SETTINGS', 0);
// config.inc.php created Link before these fixture settings changed. Its
// constructor caches rewriting/SSL flags; emulate the next native request.
$context->link = new Link();

$module = Module::getInstanceByName('paymos');
check_native($module instanceof PaymentModule, 'Released module loads with the real PaymentModule parent');
check_native(!Module::isInstalled('paymos') && $module->install(), 'Native module install and SQL migrations');
// Real merchants load a new request after install. Drop the core instance cache
// so the next object reads its active flag and restrictions from native SQL.
Module::resetStaticCache();
$module = Module::getInstanceByName('paymos');
check_native($module->active, 'Installed module reloads as active');
check_native((int) Configuration::get('PAYMOS_OS_PENDING') > 0, 'Unpaid order state installed');
// Exercise legacy upgrades against real SQL, while fixture tables are empty.
$nativeDb = new PrestaShopDb();
check_native($nativeDb->execute('ALTER TABLE `' . _DB_PREFIX_ . 'paymos_webhook_event` DROP COLUMN `owner_token`')
    && $nativeDb->execute('ALTER TABLE `' . _DB_PREFIX_ . 'paymos_invoice` DROP COLUMN `paid_late_noted`'),
    'Disposable empty tables emulate a legacy schema');
check_native(\PaymosPrestaShop\Migrations::install($nativeDb), 'Native legacy schema upgrades');
check_native(count($nativeDb->getRows('SHOW COLUMNS FROM `' . _DB_PREFIX_ . 'paymos_webhook_event` LIKE \'owner_token\'')) === 1
    && count($nativeDb->getRows('SHOW COLUMNS FROM `' . _DB_PREFIX_ . 'paymos_invoice` LIKE \'paid_late_noted\'')) === 1,
    'Both native legacy columns restored');
check_native(\PaymosPrestaShop\Migrations::install($nativeDb), 'Native migration repeats safely');
$context->controller = new AdminController('AdminModules');
AdminController::$currentIndex = '/admin-dev/index.php?controller=AdminModules';
check_native(strpos($module->getContent(), 'paymos-connect-button') !== false, 'Disconnected admin settings render Connect');
// Simulate an approved Connect result using encrypted fixture credentials. This
// exercises native storage/decryption; no real merchant or processing API exists.
$fixtureCredentials = array(
    // CredentialSet intentionally accepts only the canonical service origin.
    // All checkout/reverse calls below still use the injected MockTransport.
    'base_url' => \Paymos\Plugin\CredentialSet::DEFAULT_BASE_URL,
    'api_key' => 'pk_test_native_fixture',
    'api_secret' => 'sk_test_native_fixture',
    'project_id' => 'prj_native_fixture',
    'webhook_secret' => 'whsec_native_fixture',
);
check_native(Configuration::updateValue('PAYMOS_CREDENTIALS_V1', \Paymos\Plugin\AesGcmEnvelope::seal(
    array('schema' => 1, 'environments' => array('sandbox' => $fixtureCredentials)),
    (string) _COOKIE_KEY_, 'paymos-prestashop-credentials-v1'
)), 'Encrypted fixture connection stored in native Configuration');
$settings = $module->paymosSettings();
check_native($settings['PAYMOS_SANDBOX_PROJECT_ID'] === 'prj_native_fixture', 'Module decrypts native fixture connection');
$method = new ReflectionMethod($module, 'reconcileUrl');
$url = $method->invoke($module);
check_native(strpos($module->getContent(), htmlspecialchars($url, ENT_QUOTES, 'UTF-8')) !== false,
    'Connected native admin settings render the escaped cron URL');
parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
check_native(isset($query['token']) && hash_equals(Tools::hash('paymos/reconcile'), $query['token']), 'Native cron URL uses the legacy-compatible token');
file_put_contents('/tmp/paymos-native-cron-url', $url);

$adapter = new PrestaShopAdapter();
$db = new PrestaShopDb();
$store = new InvoiceStore($db);

function native_order($module, $context)
{
    // Each scenario represents a separate checkout request. Native Cart caches
    // include the previous customer; do not carry them across fixture orders.
    Cart::resetStaticCache();
    $customer = new Customer();
    $customer->firstname = 'Native';
    $customer->lastname = 'Fixture';
    $customer->email = 'native-' . bin2hex(random_bytes(6)) . '@example.invalid';
    $customer->passwd = password_hash('NativeFixturePassword123!', PASSWORD_BCRYPT);
    $customer->active = 1;
    check_native($customer->add(), 'Native customer created');

    $address = new Address();
    $address->id_customer = (int) $customer->id;
    $address->id_country = (int) Country::getByIso('FR');
    $address->alias = 'CI';
    $address->firstname = 'Native';
    $address->lastname = 'Fixture';
    $address->address1 = 'Disposable CI fixture';
    $address->city = 'Paris';
    $address->postcode = '75001';
    check_native($address->add(), 'Native address created');

    $product = new Product();
    foreach (Language::getLanguages(false) as $language) {
        $product->name[(int) $language['id_lang']] = 'Native virtual fixture';
        $product->link_rewrite[(int) $language['id_lang']] = 'native-virtual-fixture';
    }
    $product->price = 100;
    $product->active = 1;
    $product->available_for_order = 1;
    $product->is_virtual = 1;
    $product->id_tax_rules_group = 0;
    check_native($product->add(), 'Native virtual product created');
    StockAvailable::setQuantity((int) $product->id, 0, 10);

    $cart = new Cart();
    $cart->id_shop = (int) $context->shop->id;
    $cart->id_shop_group = (int) $context->shop->id_shop_group;
    $cart->id_lang = (int) $context->language->id;
    $cart->id_currency = (int) $context->currency->id;
    $cart->id_customer = (int) $customer->id;
    $cart->id_address_delivery = (int) $address->id;
    $cart->id_address_invoice = (int) $address->id;
    $cart->secure_key = $customer->secure_key;
    check_native($cart->add() && $cart->updateQty(1, (int) $product->id), 'Native cart contains one virtual product');
    $context->cart = $cart;
    $context->customer = $customer;
    $options = $module->hookPaymentOptions(array('cart' => $cart));
    check_native(count($options) === 1 && $options[0] instanceof \PrestaShop\PrestaShop\Core\Payment\PaymentOption,
        'Checkout hook returns a native payment option');
    check_native($options[0]->getAction() === $context->link->getModuleLink('paymos', 'validation', array(), true),
        'Checkout targets the native module validation controller URL');
    $module->validateOrder((int) $cart->id, (int) Configuration::get('PAYMOS_OS_PENDING'),
        $cart->getOrderTotal(), $module->displayName, null, array(), (int) $cart->id_currency, false, $customer->secure_key);
    $order = new Order((int) Order::getIdByCartId((int) $cart->id));
    check_native(Validate::isLoadedObject($order) && !$order->hasBeenPaid(), 'Native checkout order starts unpaid');
    return $order;
}

function native_payment_count($order)
{
    return (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'order_payment` WHERE `order_reference` = \'' . pSQL($order->reference) . '\'');
}

foreach (array('paid', 'changed-total', 'underpaid-waiting') as $scenario) {
    $order = native_order($module, $context);
    check_native(native_payment_count($order) === 0, $scenario . ': no payment at checkout');
    $invoiceId = 'inv_native_' . $order->id;
    $amount = number_format((float) $order->total_paid, 2, '.', '');
    $invoice = array(
        'invoice_id' => $invoiceId,
        'project_id' => 'prj_native_fixture',
        'status' => 'paid',
        'is_test' => true,
        'payment_url' => 'https://checkout.paymos.invalid/' . $invoiceId,
        'order' => array('external_id' => 'ps_' . $order->id . '_0', 'amount' => $amount,
            'currency' => (new Currency((int) $order->id_currency))->iso_code),
    );
    $transport = new MockTransport(array(
        new HttpResponse(200, json_encode(array_replace($invoice, array('status' => 'created'))), array()),
        new HttpResponse(200, json_encode($invoice), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_native_fixture', 'sk_test_native_fixture', 'https://api.paymos.invalid'), $transport);
    $factory = static function () use ($client) { return $client; };
    $created = (new GatewayCheckout($store, $adapter, $factory))->start((int) $order->id, $settings);
    check_native($created['invoice_id'] === $invoiceId && $store->findByOrderId((int) $order->id) !== null,
        $scenario . ': invoice created through SDK mock and stored in real SQL');

    if ($scenario === 'changed-total') {
        $order->total_paid = (float) $order->total_paid + 1;
        check_native($order->update(), 'Native order total changed after invoice creation');
    }
    if ($scenario === 'underpaid-waiting') {
        $invoice['status'] = 'underpaid_waiting';
    }
    $now = time();
    $body = json_encode(array('event_id' => 'evt_native_' . $order->id,
        'event_type' => 'invoice.' . $invoice['status'], 'version' => 1, 'occurred_at' => $now, 'data' => $invoice));
    $signature = 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $body, 'whsec_native_fixture');
    $processor = new CallbackProcessor($adapter, $store, new EventStore($db), $factory);
    check_native($processor->handle($body, 't=' . $now . ',v1=' . str_repeat('0', 64), $settings, $now)->statusCode() === 401
        && native_payment_count($order) === 0, $scenario . ': invalid HMAC cannot create a payment');
    $result = $processor->handle($body, $signature, $settings, $now);
    check_native($result->statusCode() === 200, $scenario . ': signed callback accepted');
    $fresh = new Order((int) $order->id);
    if ($scenario === 'paid') {
        check_native($fresh->hasBeenPaid() && native_payment_count($order) === 1, 'Verified paid callback creates exactly one native OrderPayment');
    } else {
        check_native(!$fresh->hasBeenPaid() && native_payment_count($order) === 0, $scenario . ': cannot become paid');
        if ($scenario === 'changed-total') {
            check_native((int) $fresh->current_state === (int) Configuration::get('PAYMOS_OS_MANUAL_REVIEW'), 'Changed amount enters native manual-review state');
        }
    }
    $requests = count($transport->requests());
    check_native($requests === ($scenario === 'underpaid-waiting' ? 1 : 2), $scenario . ': expected SDK create/reverse request count');
    check_native($processor->handle($body, $signature, $settings, $now)->statusCode() === 200
        && count($transport->requests()) === $requests
        && native_payment_count($order) === ($scenario === 'paid' ? 1 : 0), $scenario . ': duplicate does not repeat API calls or credit');
}

// Empty the disposable snapshot queue so the HTTP cron check cannot call any API.
check_native(Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'paymos_invoice`'), 'Disposable cron queue cleared');
echo "NATIVE PLATFORM PASS\n";
