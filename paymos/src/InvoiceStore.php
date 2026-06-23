<?php

declare(strict_types=1);

namespace PaymosPrestaShop;

final class InvoiceStore implements InvoiceStoreInterface
{
    /** @var DbInterface */
    private $db;

    public function __construct(DbInterface $db)
    {
        $this->db = $db;
    }

    public function findByOrderId($orderId)
    {
        return $this->db->getRow('SELECT * FROM `' . Migrations::table(Migrations::INVOICES_TABLE) . '`
            WHERE `id_order` = ' . (int) $orderId . '
            ORDER BY `id` DESC');
    }

    public function findByExternalOrderId($externalOrderId)
    {
        return $this->db->getRow('SELECT * FROM `' . Migrations::table(Migrations::INVOICES_TABLE) . '`
            WHERE `external_order_id` = \'' . $this->db->escape((string) $externalOrderId) . '\'');
    }

    public function save(array $row)
    {
        $now = date('Y-m-d H:i:s');
        $data = array(
            'id_order' => (int) (isset($row['id_order']) ? $row['id_order'] : 0),
            'id_cart' => (int) (isset($row['id_cart']) ? $row['id_cart'] : 0),
            'paymos_invoice_id' => (string) $row['paymos_invoice_id'],
            'external_order_id' => (string) $row['external_order_id'],
            'environment' => (string) $row['environment'],
            'project_id' => (string) $row['project_id'],
            'amount' => (string) $row['amount'],
            'currency' => strtoupper((string) $row['currency']),
            'payment_url' => (string) $row['payment_url'],
            'status' => (string) $row['status'],
            'renew_count' => isset($row['renew_count']) ? (int) $row['renew_count'] : 0,
            'updated_at' => $now,
        );

        $existing = $this->findByExternalOrderId($data['external_order_id']);
        if (is_array($existing)) {
            $this->db->execute('UPDATE `' . Migrations::table(Migrations::INVOICES_TABLE) . '` SET
                `id_order` = ' . (int) $data['id_order'] . ',
                `id_cart` = ' . (int) $data['id_cart'] . ',
                `paymos_invoice_id` = \'' . $this->db->escape($data['paymos_invoice_id']) . '\',
                `environment` = \'' . $this->db->escape($data['environment']) . '\',
                `project_id` = \'' . $this->db->escape($data['project_id']) . '\',
                `amount` = \'' . $this->db->escape($data['amount']) . '\',
                `currency` = \'' . $this->db->escape($data['currency']) . '\',
                `payment_url` = \'' . $this->db->escape($data['payment_url']) . '\',
                `status` = \'' . $this->db->escape($data['status']) . '\',
                `renew_count` = ' . (int) $data['renew_count'] . ',
                `updated_at` = \'' . $this->db->escape($data['updated_at']) . '\'
                WHERE `external_order_id` = \'' . $this->db->escape($data['external_order_id']) . '\'');

            return;
        }

        $data['created_at'] = $now;
        $this->db->insert(Migrations::table(Migrations::INVOICES_TABLE), $data);
    }

    public function updateStatus($paymosInvoiceId, $status)
    {
        $this->db->execute('UPDATE `' . Migrations::table(Migrations::INVOICES_TABLE) . '` SET
            `status` = \'' . $this->db->escape((string) $status) . '\',
            `updated_at` = \'' . $this->db->escape(date('Y-m-d H:i:s')) . '\'
            WHERE `paymos_invoice_id` = \'' . $this->db->escape((string) $paymosInvoiceId) . '\'');
    }

    public function findUnpaidRecent($limit, $sinceTimestamp)
    {
        $terminal = array("'paid'", "'paid_over'", "'underpaid'", "'expired'", "'cancelled'");

        return $this->db->getRows('SELECT * FROM `' . Migrations::table(Migrations::INVOICES_TABLE) . '`
            WHERE `status` NOT IN (' . implode(',', $terminal) . ')
            AND `created_at` >= \'' . $this->db->escape(date('Y-m-d H:i:s', (int) $sinceTimestamp)) . '\'
            ORDER BY `id` DESC
            LIMIT ' . (int) $limit);
    }
}
