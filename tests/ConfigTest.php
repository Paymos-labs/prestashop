<?php

declare(strict_types=1);

use PaymosPrestaShop\Config;

function test_prestashop_config_builds_client_config_and_secret_map()
{
    $config = Config::fromSettings(prestashop_settings());

    assertSameValue('sandbox', $config->environment(), 'mode setting must select sandbox environment.');
    assertSameValue('prj_123', $config->projectId(), 'sandbox project id must come from active settings.');
    assertSameValue('https://api.paymos.test', $config->clientConfig()->baseUrl(), 'base URL must be normalized into SDK config.');
    assertSameValue('pk_test_123', $config->clientConfig()->apiKey(), 'sandbox mode must use sandbox API key.');
    assertSameValue(array('sandbox' => 'whsec_sandbox', 'live' => 'whsec_live'), $config->webhookSecrets(), 'both webhook secrets should be available for callback verification.');
}

function test_prestashop_config_defaults_base_url_when_absent()
{
    $config = Config::fromSettings(prestashop_settings(array('PAYMOS_API_BASE_URL' => '')));

    assertSameValue('https://api.paymos.io', $config->clientConfig()->baseUrl(), 'missing base URL must default to the public host.');
}

function test_prestashop_generated_config_supplies_read_only_credentials()
{
    paymos_prestashop_write_generated_config("array(
        'config_version' => 2,
        'environments' => array(
            'sandbox' => array(
                'base_url' => 'https://api.paymos.io',
                'api_key' => 'pk_test_zip',
                'api_secret' => 'sk_test_zip',
                'project_id' => 'prj_zip_sandbox',
                'webhook_secret' => 'whsec_zip_sandbox',
            ),
            'live' => array(
                'base_url' => 'https://api.paymos.io',
                'api_key' => 'pk_live_zip',
                'api_secret' => 'sk_live_zip',
                'project_id' => 'prj_zip_live',
                'webhook_secret' => 'whsec_zip_live',
            ),
        ),
    )");

    $config = Config::fromSettings(prestashop_settings(array(
        'PAYMOS_MODE' => 'live',
        'PAYMOS_LIVE_API_KEY' => '',
        'PAYMOS_LIVE_API_SECRET' => '',
        'PAYMOS_LIVE_PROJECT_ID' => '',
        'PAYMOS_LIVE_WEBHOOK_SECRET' => '',
    )));

    assertSameValue('live', $config->environment(), 'generated config must still honor the mode switch.');
    assertSameValue('pk_live_zip', $config->clientConfig()->apiKey(), 'live API key must come from generated config.');
    assertSameValue('sk_live_zip', $config->clientConfig()->apiSecret(), 'live API secret must come from generated config.');
    assertSameValue('prj_zip_live', $config->projectId(), 'live project id must come from generated config.');
    assertSameValue(array('sandbox' => 'whsec_zip_sandbox', 'live' => 'whsec_zip_live'), $config->webhookSecrets(), 'generated config must provide both webhook secrets.');
}

function test_prestashop_config_rejects_mismatched_api_secret_environment()
{
    $settings = prestashop_settings(array(
        'PAYMOS_MODE' => 'live',
        'PAYMOS_LIVE_API_SECRET' => 'sk_test_123',
    ));

    try {
        Config::fromSettings($settings);
    } catch (InvalidArgumentException $e) {
        assertContainsValue('live API secret', $e->getMessage(), 'mismatched API secret error must identify the field.');

        return;
    }

    throw new RuntimeException('Config must reject API key/API secret environment mismatch.');
}

function test_prestashop_config_rejects_missing_selected_environment_webhook_secret()
{
    $settings = prestashop_settings(array(
        'PAYMOS_MODE' => 'live',
        'PAYMOS_LIVE_WEBHOOK_SECRET' => '',
    ));

    try {
        Config::fromSettings($settings);
    } catch (InvalidArgumentException $e) {
        assertContainsValue('live webhook secret', strtolower($e->getMessage()), 'missing selected environment webhook secret must be explicit.');

        return;
    }

    throw new RuntimeException('Config must reject live mode without live webhook secret.');
}

function test_prestashop_config_masks_api_key_for_diagnostics()
{
    $masked = Config::fromSettings(prestashop_settings())->maskedApiKey();

    assertContainsValue('pk_test', $masked, 'masked key keeps the visible prefix.');
    assertSameValue(false, strpos($masked, 'sk_test') !== false, 'masked key never exposes the secret.');
    assertContainsValue('•', $masked, 'masked key hides the middle.');
}
