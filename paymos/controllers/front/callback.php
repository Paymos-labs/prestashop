<?php
/**
 * Paymos webhook callback controller.
 *
 * Reads the raw body + X-Webhook-Signature header, hands them to the SDK-backed
 * CallbackProcessor, and emits the resulting status with http_response_code().
 *
 * NEVER emit a raw "HTTP/1.1 …" status line — it is invalid under HTTP/2 and the
 * front controller / web server may discard it, returning 200 with junk. The
 * Paymos webhook worker would then treat a failed callback as delivered and
 * never retry.
 *
 * @author    Paymos
 * @copyright Paymos
 * @license   https://opensource.org/licenses/MIT MIT License
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class PaymosCallbackModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ssl = true;

    /** Webhook is server-to-server: no CSRF token, no maintenance gate, no auth. */
    public $auth = false;

    public function initContent()
    {
        $rawBody = file_get_contents('php://input');
        $signature = $this->signatureHeader();

        try {
            \PaymosPrestaShop\Migrations::install(new \PaymosPrestaShop\PrestaShopDb());

            $result = (new \PaymosPrestaShop\CallbackProcessor(
                new \PaymosPrestaShop\PrestaShopAdapter(),
                new \PaymosPrestaShop\InvoiceStore(new \PaymosPrestaShop\PrestaShopDb()),
                new \PaymosPrestaShop\EventStore(new \PaymosPrestaShop\PrestaShopDb())
            ))->handle($rawBody === false ? '' : $rawBody, $signature, $this->module->paymosSettings());

            $this->respond($result->statusCode(), $result->body());
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('[Paymos] Callback fatal: ' . $e->getMessage(), 3, null, 'PaymosPrestaShop');
            $this->respond(500, 'Internal error');
        }
    }

    private function signatureHeader()
    {
        // The server signs webhooks with the `X-Webhook-Signature` header, which
        // PHP exposes as HTTP_X_WEBHOOK_SIGNATURE. No other header carries it.
        if (isset($_SERVER['HTTP_X_WEBHOOK_SIGNATURE']) && $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] !== '') {
            return (string) $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'];
        }

        return '';
    }

    private function respond($statusCode, $body)
    {
        http_response_code((int) $statusCode);
        header('Content-Type: text/plain; charset=utf-8');
        echo $body;
        exit;
    }
}
