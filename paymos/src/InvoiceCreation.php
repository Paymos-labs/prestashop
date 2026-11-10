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
    public static function isPending(array $row): bool
    {
        return ($row['status'] ?? '') === 'creating';
    }

    public static function pending(array $row, array $payload): array
    {
        $row['paymos_invoice_id'] = 'pending:' . hash('sha256', (string) $row['external_order_id']);
        $row['payment_url'] = json_encode($payload, JSON_THROW_ON_ERROR);
        $row['status'] = 'creating';
        return $row;
    }

    public static function recover(array $row, InvoiceStoreInterface $store, $client, callable $assertOwned): array
    {
        if (!self::isPending($row)) { return $row; }
        $payload = json_decode((string) $row['payment_url'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || ($payload['external_order_id'] ?? '') !== $row['external_order_id']
            || ($payload['project_id'] ?? '') !== $row['project_id']
            || ($payload['amount'] ?? '') !== $row['amount'] || ($payload['currency'] ?? '') !== $row['currency']) {
            throw new \RuntimeException('Paymos invoice creation intent is invalid.');
        }
        $response = $client->invoices()->create($payload);
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
