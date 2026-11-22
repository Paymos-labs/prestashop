<?php

declare(strict_types=1);

namespace PaymosPrestaShop;

use Paymos\Plugin\StatusMapper;

/** A durable creation intent uses the existing snapshot row, before any HTTP.
 * While status=creating, payment_url contains the immutable request JSON, never
 * a redirect URL. A unique pending invoice sentinel avoids a schema migration.
 */
final class InvoiceCreation
{
    /**
     * Conservative UTF-8 byte ceiling for the durable pending intent JSON stored
     * in `payment_url`. Stays safely below MySQL's 65,535-byte TEXT limit even if
     * a shop has not yet widened the column to MEDIUMTEXT, while preserving
     * `products` in normal multi-item UTF-8 carts. When a large cart exceeds this
     * budget, `products` is omitted from the durable snapshot (Paymos idempotency
     * does not compare `products`) while the live create call still sends it.
     */
    public const MAX_PENDING_JSON_BYTES = 60000;

    public static function isPending(array $row): bool
    {
        return ($row['status'] ?? '') === 'creating';
    }

    public static function pending(array $row, array $payload): array
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        $encoded = json_encode($payload, $flags);
        if (strlen($encoded) > self::MAX_PENDING_JSON_BYTES && array_key_exists('products', $payload)) {
            $compact = $payload;
            unset($compact['products']);
            $encoded = json_encode($compact, $flags);
        }
        $row['paymos_invoice_id'] = 'pending:' . hash('sha256', (string) $row['external_order_id']);
        $row['payment_url'] = $encoded;
        $row['status'] = 'creating';
        return $row;
    }

    public static function recover(array $row, InvoiceStoreInterface $store, $client, callable $assertOwned, ?array $livePayload = null): array
    {
        if (!self::isPending($row)) { return $row; }
        $payload = json_decode((string) $row['payment_url'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || ($payload['external_order_id'] ?? '') !== $row['external_order_id']
            || ($payload['project_id'] ?? '') !== $row['project_id']
            || ($payload['amount'] ?? '') !== $row['amount'] || ($payload['currency'] ?? '') !== $row['currency']) {
            throw new \RuntimeException('Paymos invoice creation intent is invalid.');
        }
        $outgoing = $payload;
        if ($livePayload !== null) {
            if (($livePayload['external_order_id'] ?? '') !== $row['external_order_id']
                || ($livePayload['project_id'] ?? '') !== $row['project_id']
                || ($livePayload['amount'] ?? '') !== $row['amount']
                || ($livePayload['currency'] ?? '') !== $row['currency']) {
                throw new \RuntimeException('Paymos live invoice creation payload does not match durable intent.');
            }
            $outgoing = $livePayload;
        }
        // Decode and project a copy; never rewrite the saved pending request for this retired key.
        if (isset($outgoing['products']) && is_array($outgoing['products'])) {
            foreach ($outgoing['products'] as $index => $item) {
                if (is_array($item)) { unset($outgoing['products'][$index]['image_url']); }
            }
        }
        $response = $client->invoices()->create($outgoing);
        $assertOwned();
        if (!is_string($response['invoice_id'] ?? null) || $response['invoice_id'] === ''
            || !is_string($response['payment_url'] ?? null) || $response['payment_url'] === '') {
            throw new \RuntimeException('Paymos invoice create response is missing invoice id or payment URL.');
        }
        $row['paymos_invoice_id'] = $response['invoice_id'];
        $row['payment_url'] = $response['payment_url'];
        $status = (string) ($response['status'] ?? '');
        // A returned terminal state is not evidence of a native applied payment.
        $row['status'] = StatusMapper::isFinalStatus($status) ? 'created' : ($status ?: 'created');
        $store->save($row);
        return $row;
    }
}
