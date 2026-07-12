<?php

declare(strict_types=1);

namespace PaymosPrestaShop;

use Paymos\ClientConfig;

/** Resolves the encrypted credentials stored by the Paymos Connect flow. */
final class Config
{
    public const DEFAULT_BASE_URL = 'https://api.paymos.io';

    /** @var array<string, mixed> */
    private static $testConfig = array();

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
        $baseUrl = $this->environmentValue($environment, 'base_url');

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
        self::$testConfig = array();
    }

    /** @param array<string, mixed> $config */
    public static function useConfigForTests(array $config)
    {
        self::$testConfig = $config;
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
        $test = self::testEnvironment($environment);
        if (isset($test[$field]) && is_scalar($test[$field]) && trim((string) $test[$field]) !== '') {
            return trim((string) $test[$field]);
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

    /** @return array<string, mixed> */
    private static function testEnvironment($environment)
    {
        $environments = isset(self::$testConfig['environments']) && is_array(self::$testConfig['environments'])
            ? self::$testConfig['environments']
            : array();
        return isset($environments[$environment]) && is_array($environments[$environment])
            ? $environments[$environment]
            : array();
    }

}
