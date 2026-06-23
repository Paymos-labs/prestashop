<?php

declare(strict_types=1);

namespace PaymosPrestaShop;

use Paymos\ClientConfig;

/**
 * Resolves Paymos credentials and presentation for the PrestaShop module.
 *
 * Two-tier, exactly like every other Paymos CMS plugin:
 *   1. The dashboard-generated paymos-config.php (v2 shape:
 *      {config_version:2, environments:{sandbox:{…}, live:{…}}}) carries the
 *      read-only secrets and OVERRIDES anything stored in PrestaShop config.
 *   2. The PrestaShop `Configuration` tier (passed in as $settings) supplies the
 *      mode + API base URL, and the per-environment credential keys
 *      (PAYMOS_<ENV>_<FIELD>) act as a fallback. In the shipped product those
 *      credential keys are only ever populated by the generated config (the
 *      admin screen does NOT expose secret fields); the settings tier is the
 *      injection seam the test suite uses to exercise Config without writing a
 *      generated file.
 *
 * The merchant never types secrets — they arrive inside the ZIP. The admin
 * screen only exposes `mode` (sandbox/live) and the API base URL. base_url
 * defaults to https://api.paymos.io when the generated config omits it.
 */
final class Config
{
    public const DEFAULT_BASE_URL = 'https://api.paymos.io';

    /** @var array<string, mixed>|null */
    private static $generated;

    /** @var array<string, string> */
    private $settings;

    /**
     * @param array<string, string> $settings
     */
    private function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    /**
     * @param array<string, string> $settings PrestaShop Configuration values, keyed by name.
     */
    public static function fromSettings(array $settings)
    {
        $config = new self($settings);
        $environment = $config->environment();

        $config->assertEnvironmentConfigured($environment);

        $secrets = $config->webhookSecrets();
        if (count($secrets) === 0) {
            throw new \InvalidArgumentException('At least one Paymos webhook secret is required.');
        }
        if (!isset($secrets[$environment])) {
            throw new \InvalidArgumentException('Paymos ' . $environment . ' webhook secret is required for the selected mode.');
        }

        return $config;
    }

    public function clientConfig()
    {
        return $this->clientConfigForEnvironment($this->environment());
    }

    public function clientConfigForEnvironment($environment)
    {
        $environment = $this->normalizeEnvironment($environment);
        $this->assertEnvironmentConfigured($environment);

        return new ClientConfig(
            $this->apiKey($environment),
            $this->apiSecret($environment),
            $this->apiBaseUrlForEnvironment($environment),
            30
        );
    }

    public function apiKey($environment = null)
    {
        $environment = $environment === null ? $this->environment() : $this->normalizeEnvironment($environment);

        return $this->environmentValue($environment, 'api_key');
    }

    public function apiSecret($environment = null)
    {
        $environment = $environment === null ? $this->environment() : $this->normalizeEnvironment($environment);

        return $this->environmentValue($environment, 'api_secret');
    }

    public function projectId($environment = null)
    {
        $environment = $environment === null ? $this->environment() : $this->normalizeEnvironment($environment);

        return $this->environmentValue($environment, 'project_id');
    }

    public function apiBaseUrlForEnvironment($environment)
    {
        $environment = $this->normalizeEnvironment($environment);
        $generated = self::generatedEnvironment($environment);
        if (isset($generated['base_url']) && is_scalar($generated['base_url']) && trim((string) $generated['base_url']) !== '') {
            return rtrim((string) $generated['base_url'], '/');
        }

        $baseUrl = $this->setting('PAYMOS_API_BASE_URL');

        return $baseUrl === '' ? self::DEFAULT_BASE_URL : rtrim($baseUrl, '/');
    }

    public function environment()
    {
        $mode = strtolower($this->setting('PAYMOS_MODE'));

        return in_array($mode, array('sandbox', 'live'), true) ? $mode : 'sandbox';
    }

    /**
     * @return array<string, string>
     */
    public function webhookSecrets()
    {
        $secrets = array();
        foreach (array('sandbox', 'live') as $environment) {
            $secret = $this->environmentValue($environment, 'webhook_secret');
            if ($secret !== '') {
                $secrets[$environment] = $secret;
            }
        }

        return $secrets;
    }

    public static function hasGeneratedConfig()
    {
        $generated = self::generated();

        return isset($generated['environments']) && is_array($generated['environments']);
    }

    public function maskedApiKey()
    {
        $apiKey = $this->environmentValue($this->environment(), 'api_key');
        if ($apiKey === '') {
            return '';
        }

        if (strlen($apiKey) <= 10) {
            return str_repeat('•', strlen($apiKey));
        }

        return substr($apiKey, 0, 7) . str_repeat('•', 6) . substr($apiKey, -4);
    }

    public static function resetForTests()
    {
        self::$generated = null;
    }

    private function assertEnvironmentConfigured($environment)
    {
        $environment = $this->normalizeEnvironment($environment);
        $fields = array(
            'API key' => 'api_key',
            'API secret' => 'api_secret',
            'project id' => 'project_id',
            'webhook secret' => 'webhook_secret',
        );

        foreach ($fields as $label => $field) {
            if ($this->environmentValue($environment, $field) === '') {
                throw new \InvalidArgumentException('Paymos PrestaShop config is missing ' . $environment . ' ' . $label . '.');
            }
        }

        $this->assertApiKeyMatchesEnvironment($environment);
        $this->assertApiSecretMatchesEnvironment($environment);
    }

    private function assertApiKeyMatchesEnvironment($environment)
    {
        $apiKey = $this->apiKey($environment);
        if ($environment === 'sandbox' && strpos($apiKey, 'pk_test_') !== 0) {
            throw new \InvalidArgumentException('Paymos sandbox API key must start with pk_test_.');
        }
        if ($environment === 'live' && strpos($apiKey, 'pk_live_') !== 0) {
            throw new \InvalidArgumentException('Paymos live API key must start with pk_live_.');
        }
    }

    private function assertApiSecretMatchesEnvironment($environment)
    {
        $apiSecret = $this->apiSecret($environment);
        if ($environment === 'sandbox' && strpos($apiSecret, 'sk_test_') !== 0) {
            throw new \InvalidArgumentException('Paymos sandbox API secret must start with sk_test_.');
        }
        if ($environment === 'live' && strpos($apiSecret, 'sk_live_') !== 0) {
            throw new \InvalidArgumentException('Paymos live API secret must start with sk_live_.');
        }
    }

    private function environmentValue($environment, $field)
    {
        $environment = $this->normalizeEnvironment($environment);
        $generated = self::generatedEnvironment($environment);
        if (isset($generated[$field]) && is_scalar($generated[$field]) && trim((string) $generated[$field]) !== '') {
            return trim((string) $generated[$field]);
        }

        return $this->setting('PAYMOS_' . strtoupper($environment) . '_' . strtoupper($field));
    }

    private function normalizeEnvironment($environment)
    {
        $environment = strtolower(trim((string) $environment));
        if (!in_array($environment, array('sandbox', 'live'), true)) {
            throw new \InvalidArgumentException('Paymos environment must be sandbox or live.');
        }

        return $environment;
    }

    private function setting($key)
    {
        return isset($this->settings[$key]) && is_scalar($this->settings[$key])
            ? trim((string) $this->settings[$key])
            : '';
    }

    /**
     * @return array<string, mixed>
     */
    private static function generatedEnvironment($environment)
    {
        $generated = self::generated();
        if (!isset($generated['environments']) || !is_array($generated['environments'])) {
            return array();
        }

        $environments = $generated['environments'];

        return isset($environments[$environment]) && is_array($environments[$environment])
            ? $environments[$environment]
            : array();
    }

    /**
     * @return array<string, mixed>
     */
    private static function generated()
    {
        if (self::$generated !== null) {
            return self::$generated;
        }

        $file = dirname(__DIR__) . '/paymos-config.php';
        if (!is_readable($file)) {
            self::$generated = array();

            return self::$generated;
        }

        $config = require $file;
        self::$generated = is_array($config) ? $config : array();

        return self::$generated;
    }
}
