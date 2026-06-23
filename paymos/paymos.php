<?php
/**
 * Paymos — accept stablecoin payments (USDT / USDC) on PrestaShop.
 *
 * Hosted-checkout payment module. The customer is redirected to the Paymos
 * hosted checkout; a signed webhook drives the order state. All crypto-critical
 * logic (request signing, webhook verification, status mapping, amount guarding,
 * reverse verification) lives in the vendored Paymos PHP SDK — this module is a
 * thin PrestaShop shell over it.
 *
 * @author    Paymos
 * @copyright Paymos
 * @license   https://opensource.org/licenses/MIT MIT License
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/src/Autoloader.php';
\PaymosPrestaShop\Autoloader::register();

use PaymosPrestaShop\Config;
use PaymosPrestaShop\Migrations;
use PaymosPrestaShop\PrestaShopDb;
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

class Paymos extends PaymentModule
{
    /** @var array<int, string> */
    private $postErrors = array();

    /**
     * Custom order states created on install, keyed by the Configuration key that
     * stores the resulting state id. Each carries its presentation.
     *
     * @var array<string, array<string, mixed>>
     */
    private static $customStates = array(
        'PAYMOS_OS_PENDING' => array(
            'name' => 'Awaiting Paymos payment',
            'color' => '#5A6BFF',
            'paid' => false,
            'logable' => false,
        ),
        'PAYMOS_OS_CONFIRMING' => array(
            'name' => 'Paymos payment confirming',
            'color' => '#34C77B',
            'paid' => false,
            'logable' => false,
        ),
        'PAYMOS_OS_MANUAL_REVIEW' => array(
            'name' => 'Paymos payment — manual review',
            'color' => '#F0A33A',
            'paid' => false,
            'logable' => false,
        ),
    );

    public function __construct()
    {
        $this->name = 'paymos';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->author = 'Paymos';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array('min' => '1.7.6.0', 'max' => _PS_VERSION_);
        $this->controllers = array('validation', 'callback', 'pending', 'reconcile');
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Paymos');
        $this->description = $this->l('Accept USDT and USDC at checkout. Funds settle straight to your wallet — no chargebacks, no card rails.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall Paymos? Your existing orders keep their history.');
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        if (!$this->registerHook('paymentOptions') || !$this->registerHook('paymentReturn')) {
            return false;
        }

        if (!$this->installOrderStates()) {
            return false;
        }

        Configuration::updateValue('PAYMOS_MODE', 'sandbox');
        Configuration::updateValue('PAYMOS_API_BASE_URL', Config::DEFAULT_BASE_URL);

        return Migrations::install(new PrestaShopDb());
    }

    public function uninstall()
    {
        // Order states are intentionally left in place: PrestaShop forbids
        // deleting a state that historical orders reference, and silently
        // orphaning past orders is worse than a leftover state. Only the module
        // tables and the mode/base-url config are removed.
        Migrations::uninstall(new PrestaShopDb());

        Configuration::deleteByName('PAYMOS_MODE');
        Configuration::deleteByName('PAYMOS_API_BASE_URL');
        Configuration::deleteByName('PAYMOS_SANDBOX_API_KEY');
        Configuration::deleteByName('PAYMOS_SANDBOX_API_SECRET');
        Configuration::deleteByName('PAYMOS_SANDBOX_PROJECT_ID');
        Configuration::deleteByName('PAYMOS_SANDBOX_WEBHOOK_SECRET');
        Configuration::deleteByName('PAYMOS_LIVE_API_KEY');
        Configuration::deleteByName('PAYMOS_LIVE_API_SECRET');
        Configuration::deleteByName('PAYMOS_LIVE_PROJECT_ID');
        Configuration::deleteByName('PAYMOS_LIVE_WEBHOOK_SECRET');

        return parent::uninstall();
    }

    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return array();
        }

        if (!$this->checkCurrency($params['cart'])) {
            return array();
        }

        $option = new PaymentOption();
        $option->setModuleName($this->name)
            ->setCallToActionText($this->l('Pay with stablecoins (USDT / USDC)'))
            ->setAction($this->context->link->getModuleLink($this->name, 'validation', array(), true))
            ->setAdditionalInformation($this->fetch('module:paymos/views/templates/hook/payment_options.tpl'));

        // Only attach a logo when the brand asset is actually present, so the
        // option never renders a broken image. Drop a `logo.png` into the module
        // root to enable it.
        $logoFile = _PS_MODULE_DIR_ . $this->name . '/logo.png';
        if (is_file($logoFile)) {
            $option->setLogo(Media::getMediaPath($logoFile));
        }

        return array($option);
    }

    public function hookPaymentReturn($params)
    {
        if (!$this->active || !isset($params['order'])) {
            return '';
        }

        $order = $params['order'];
        if ($order->module !== $this->name) {
            return '';
        }

        $this->context->smarty->assign(array(
            'paymos_status_message' => $this->l('Your Paymos payment is being verified. This order will update automatically once the payment is confirmed on-chain.'),
        ));

        return $this->fetch('module:paymos/views/templates/hook/payment_return.tpl');
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitPaymos')) {
            $this->postValidation();
            if (count($this->postErrors) === 0) {
                $this->postProcess();
                $output .= $this->displayConfirmation($this->l('Settings updated.'));
            } else {
                foreach ($this->postErrors as $error) {
                    $output .= $this->displayError($error);
                }
            }
        }

        return $output . $this->renderConfigStatus() . $this->renderForm();
    }

    private function installOrderStates()
    {
        foreach (self::$customStates as $configKey => $definition) {
            if ((int) Configuration::get($configKey) > 0) {
                continue;
            }

            $state = new OrderState();
            $state->name = array();
            foreach (Language::getLanguages(false) as $language) {
                $state->name[$language['id_lang']] = $definition['name'];
            }
            $state->send_email = false;
            $state->color = $definition['color'];
            $state->hidden = false;
            $state->delivery = false;
            $state->shipped = false;
            $state->paid = (bool) $definition['paid'];
            $state->logable = (bool) $definition['logable'];
            $state->invoice = false;
            $state->module_name = $this->name;

            if (!$state->add()) {
                return false;
            }

            Configuration::updateValue($configKey, (int) $state->id);
        }

        return true;
    }

    private function checkCurrency($cart)
    {
        $currencyOrder = new Currency((int) $cart->id_currency);
        $currenciesModule = $this->getCurrency((int) $cart->id_currency);

        if (!is_array($currenciesModule)) {
            return false;
        }

        foreach ($currenciesModule as $currencyModule) {
            if ($currencyOrder->id == $currencyModule['id_currency']) {
                return true;
            }
        }

        return false;
    }

    private function postValidation()
    {
        $mode = Tools::getValue('PAYMOS_MODE');
        if (!in_array($mode, array('sandbox', 'live'), true)) {
            $this->postErrors[] = $this->l('Select a valid mode (Sandbox or Live).');
        }

        $baseUrl = trim((string) Tools::getValue('PAYMOS_API_BASE_URL'));
        if ($baseUrl !== '' && !Validate::isUrl($baseUrl)) {
            $this->postErrors[] = $this->l('The API base URL is not a valid URL.');
        }
    }

    private function postProcess()
    {
        Configuration::updateValue('PAYMOS_MODE', Tools::getValue('PAYMOS_MODE'));

        $baseUrl = trim((string) Tools::getValue('PAYMOS_API_BASE_URL'));
        Configuration::updateValue('PAYMOS_API_BASE_URL', $baseUrl === '' ? Config::DEFAULT_BASE_URL : $baseUrl);
    }

    private function renderConfigStatus()
    {
        $hasGenerated = Config::hasGeneratedConfig();
        $mode = Configuration::get('PAYMOS_MODE') ?: 'sandbox';
        $masked = '';
        $projectId = '';

        if ($hasGenerated) {
            try {
                $config = Config::fromSettings($this->currentSettings());
                $masked = $config->maskedApiKey();
                $projectId = $config->projectId();
            } catch (\Exception $e) {
                $masked = '';
            }
        }

        $this->context->smarty->assign(array(
            'paymos_has_generated' => $hasGenerated,
            'paymos_mode' => $mode,
            'paymos_masked_key' => $masked,
            'paymos_project_id' => $projectId,
            'paymos_callback_url' => $this->context->link->getModuleLink($this->name, 'callback', array(), true),
            'paymos_reconcile_url' => $this->reconcileUrl(),
        ));

        return $this->display(__FILE__, 'views/templates/admin/config_status.tpl');
    }

    /**
     * Public HTTP URL of the reconcile cron, including the secure token the cron
     * checks (Tools::encrypt('paymos/reconcile')). Shown in the admin panel so the
     * merchant can wire it into a scheduler. Returns '' if the base shop URL is
     * unknown.
     */
    private function reconcileUrl()
    {
        // Use the front-controller URL, not a direct modules/**.php path: PrestaShop
        // 9.0 denies direct access to .php files under modules/, which would 403 a
        // cron pointed at cron/reconcile.php. The front controller is PS-9-safe.
        return $this->context->link->getModuleLink(
            $this->name,
            'reconcile',
            array('token' => Tools::encrypt('paymos/reconcile')),
            true
        );
    }

    private function renderForm()
    {
        $fieldsForm = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Paymos settings'),
                    'icon' => 'icon-cogs',
                ),
                'description' => $this->l('Credentials are injected from the package you downloaded in the Paymos dashboard and are read-only. Only the mode and API base URL are editable here.'),
                'input' => array(
                    array(
                        'type' => 'radio',
                        'label' => $this->l('Mode'),
                        'name' => 'PAYMOS_MODE',
                        'values' => array(
                            array('id' => 'mode_sandbox', 'value' => 'sandbox', 'label' => $this->l('Sandbox')),
                            array('id' => 'mode_live', 'value' => 'live', 'label' => $this->l('Live')),
                        ),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('API base URL'),
                        'name' => 'PAYMOS_API_BASE_URL',
                        'desc' => $this->l('Leave as https://api.paymos.io unless Paymos support tells you otherwise.'),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitPaymos';
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->fields_value = array(
            'PAYMOS_MODE' => Configuration::get('PAYMOS_MODE') ?: 'sandbox',
            'PAYMOS_API_BASE_URL' => Configuration::get('PAYMOS_API_BASE_URL') ?: Config::DEFAULT_BASE_URL,
        );

        return $helper->generateForm(array($fieldsForm));
    }

    /**
     * @return array<string, string>
     */
    private function currentSettings()
    {
        $keys = array(
            'PAYMOS_MODE',
            'PAYMOS_API_BASE_URL',
            'PAYMOS_SANDBOX_API_KEY',
            'PAYMOS_SANDBOX_API_SECRET',
            'PAYMOS_SANDBOX_PROJECT_ID',
            'PAYMOS_SANDBOX_WEBHOOK_SECRET',
            'PAYMOS_LIVE_API_KEY',
            'PAYMOS_LIVE_API_SECRET',
            'PAYMOS_LIVE_PROJECT_ID',
            'PAYMOS_LIVE_WEBHOOK_SECRET',
        );

        $settings = array();
        foreach ($keys as $key) {
            $settings[$key] = (string) Configuration::get($key);
        }

        return $settings;
    }

    /**
     * Shared settings reader used by the front controllers.
     *
     * @return array<string, string>
     */
    public function paymosSettings()
    {
        return $this->currentSettings();
    }
}
