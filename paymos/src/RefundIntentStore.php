<?php

declare(strict_types=1);

namespace PaymosPrestaShop;

use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\EffectClaim;
use Paymos\Plugin\NativeProjectionResult;
use Paymos\Plugin\RefundIntent;
use Paymos\Plugin\RefundIntentPage;
use Paymos\Plugin\RefundIntentStoreInterface;
use Paymos\Plugin\RefundStates;
use Paymos\Plugin\VerifiedRefund;

final class RefundIntentStore implements RefundIntentStoreInterface
{
    private $db;
    private $clock;
    private $paymentKey;
    private $lockName;
    private $inTransaction = false;

    public function __construct(DbInterface $db, ?callable $clock = null)
    {
        $this->db = $db;
        $this->clock = $clock ?: static function () { return new \DateTimeImmutable('now'); };
    }

    public static function install(DbInterface $db): void
    {
        $schemas = array(
            'installation' => '`id` INT NOT NULL, `installation_id` CHAR(36) NOT NULL, PRIMARY KEY (`id`)',
            'intent' => '`operation_id` CHAR(36) NOT NULL, `installation_id` CHAR(36) NOT NULL,
                `request_token` VARCHAR(64) NOT NULL, `payment_key` VARCHAR(128) NOT NULL,
                `external_order_id` VARCHAR(200) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `withdrawal_id` VARCHAR(128) NULL,
                `payload` LONGTEXT NOT NULL, `created_at` BIGINT NOT NULL, `next_due` BIGINT NOT NULL,
                `poll_count` INT NOT NULL DEFAULT 0, PRIMARY KEY (`operation_id`),
                UNIQUE KEY `request_token` (`installation_id`,`request_token`),
                UNIQUE KEY `external_order_id` (`external_order_id`), UNIQUE KEY `withdrawal_id` (`withdrawal_id`),
                KEY `payment_key` (`payment_key`), KEY `next_due` (`next_due`,`operation_id`)',
            'projection' => '`operation_id` VARCHAR(160) NOT NULL, `payment_key` VARCHAR(128) NOT NULL,
                `withdrawal_id` VARCHAR(128) NOT NULL, `mode` VARCHAR(16) NOT NULL,
                `native_id` VARCHAR(128) NOT NULL, `result` LONGTEXT NOT NULL, `mail` LONGTEXT NULL,
                PRIMARY KEY (`operation_id`), UNIQUE KEY `withdrawal_id` (`withdrawal_id`),
                UNIQUE KEY `native_marker` (`mode`,`native_id`)',
            'unbound' => '`withdrawal_id` VARCHAR(128) NOT NULL, `payment_key` VARCHAR(128) NOT NULL,
                `payload` LONGTEXT NOT NULL, `review_reason` VARCHAR(128) NULL,
                `next_due` BIGINT NOT NULL, PRIMARY KEY (`withdrawal_id`), KEY `next_due` (`next_due`)',
        );
        foreach ($schemas as $name => $schema) {
            if (!$db->execute('CREATE TABLE IF NOT EXISTS `' . Migrations::table('paymos_refund_' . $name)
                . '` (' . $schema . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin')) {
                throw new \RuntimeException('refund_schema_install_failed');
            }
        }
    }

    public function installationId(): string
    {
        $this->execute('INSERT IGNORE INTO ' . $this->table('installation')
            . ' (`id`,`installation_id`) VALUES (1,' . $this->quote(self::uuid()) . ')');
        $row = $this->db->getRow('SELECT * FROM ' . $this->table('installation') . ' WHERE `id` = 1');
        if ($row === null) {
            throw new \RuntimeException('refund_installation_missing');
        }
        return (string) $row['installation_id'];
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
        $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    public function withPaymentLock(string $paymentKey, callable $action)
    {
        if ($this->paymentKey !== null) {
            $this->assertLock($paymentKey);
            return $action();
        }
        $name = 'pmrf:ps:' . substr(hash('sha256', $this->installationId() . ':' . $paymentKey), 0, 48);
        if ($this->db->getValue('SELECT GET_LOCK(' . $this->quote($name) . ',0) AS acquired') !== '1') {
            throw new \RuntimeException('refund_payment_busy');
        }
        $this->paymentKey = $paymentKey;
        $this->lockName = $name;
        try {
            $this->assertLock($paymentKey);
            $result = $action();
            $this->assertLock($paymentKey);
            return $result;
        } finally {
            try {
                $this->db->getValue('SELECT RELEASE_LOCK(' . $this->quote($name) . ') AS released');
            } finally {
                $this->paymentKey = null;
                $this->lockName = null;
            }
        }
    }

    public function assertLock(string $paymentKey): void
    {
        if ($this->paymentKey !== $paymentKey || $this->lockName === null
            || $this->db->getValue('SELECT IS_USED_LOCK(' . $this->quote((string) $this->lockName)
                . ') = CONNECTION_ID() AS owned') !== '1') {
            throw new \RuntimeException('refund_payment_lock_lost');
        }
    }

    public function transaction(callable $action)
    {
        $this->assertLock((string) $this->paymentKey);
        if ($this->inTransaction) {
            return $action();
        }
        $this->execute('START TRANSACTION');
        $this->inTransaction = true;
        try {
            $result = $action();
            $this->assertLock((string) $this->paymentKey);
            $this->execute('COMMIT');
            return $result;
        } catch (\Throwable $error) {
            $this->execute('ROLLBACK');
            throw $error;
        } finally {
            $this->inTransaction = false;
        }
    }

    public function createIntent(RefundIntent $intent): RefundIntent
    {
        if ($this->inTransaction) {
            throw new \RuntimeException('refund_intent_requires_commit');
        }
        $this->assertLock($intent->paymentKey());
        return $this->transaction(function () use ($intent) {
            $data = $intent->toArray();
            $old = $this->findByRequestToken($data['installation_id'], $data['request_token']);
            if ($old !== null) {
                foreach (array('platform', 'native_payment_key', 'invoice_id', 'project_id', 'environment', 'mode', 'full_draft', 'native_identity') as $field) {
                    if (($old->toArray()[$field] ?? null) !== ($data[$field] ?? null)) {
                        throw new \RuntimeException('refund_request_conflict');
                    }
                }
                return $old;
            }
            if ($data['platform'] !== 'prestashop' || $data['installation_id'] !== $this->installationId()
                || !preg_match('/^[a-zA-Z0-9-]{16,64}$/D', $data['request_token'])
                || !preg_match('/^[a-f0-9-]{36}$/D', $intent->operationId())
                || $intent->cashState() !== RefundStates::NOT_SUBMITTED || $intent->withdrawalId() !== null
                || $intent->observation() !== null || $data['projection'] !== null || $data['effects'] !== array()
                || $intent->projectionState() !== RefundStates::PROJECTION_NONE) {
                throw new \RuntimeException('refund_invalid_new_intent');
            }
            $this->assertNewActionAllowed($intent->paymentKey());
            $now = $this->now()->getTimestamp();
            $data['created_at'] = $now;
            $this->insert('intent', array('operation_id' => $intent->operationId(), 'installation_id' => $data['installation_id'],
                'request_token' => $data['request_token'], 'payment_key' => $intent->paymentKey(),
                'external_order_id' => $intent->externalOrderId(), 'withdrawal_id' => null,
                'payload' => $this->encode($data), 'created_at' => $now, 'next_due' => $now, 'poll_count' => 0));
            return $this->getIntent($intent->operationId());
        });
    }

    public function assertNewActionAllowed(string $paymentKey): void
    {
        $this->assertLock($paymentKey);
        foreach ($this->forPayment($paymentKey) as $old) {
            if ($old->projectionState() === RefundStates::NATIVE_REVIEW
                || !in_array($old->cashState(), array('completed', 'failed', 'cancelled'), true)
                || ($old->cashState() === 'completed' && $old->projectionState() !== RefundStates::PROJECTION_APPLIED)
                || ($old->mode() === RefundStates::FULL && $old->toArray()['projection'] !== null)) {
                throw new \RuntimeException('refund_payment_unresolved_or_refunded');
            }
        }
        foreach ($this->unboundForPayment($paymentKey) as $row) {
            if ($row['review_reason'] !== null) {
                throw new \RuntimeException('refund_unbound_requires_review');
            }
        }
    }

    public function findByRequestToken(string $installationId, string $token): ?RefundIntent
    {
        return $this->find('`installation_id` = ' . $this->quote($installationId) . ' AND `request_token` = ' . $this->quote($token));
    }

    public function getIntent(string $operationId): RefundIntent
    {
        $intent = $this->find('`operation_id` = ' . $this->quote($operationId));
        if ($intent === null) { throw new \RuntimeException('refund_intent_missing'); }
        return $intent;
    }

    public function findByExternalId(string $key): ?RefundIntent
    {
        return $this->find('`external_order_id` = ' . $this->quote($key));
    }

    public function findByWithdrawalId(string $id): ?RefundIntent
    {
        return $this->find('`withdrawal_id` = ' . $this->quote($id));
    }

    private function find(string $where): ?RefundIntent
    {
        $row = $this->db->getRow('SELECT * FROM ' . $this->table('intent') . ' WHERE ' . $where);
        return $row === null ? null : new RefundIntent($this->decode($row['payload']));
    }

    public function forPayment(string $key): array
    {
        return array_map(function ($row) { return new RefundIntent($this->decode($row['payload'])); },
            $this->db->getRows('SELECT * FROM ' . $this->table('intent') . ' WHERE `payment_key` = ' . $this->quote($key) . ' ORDER BY `created_at`,`operation_id`'));
    }

    public function unboundForPayment(string $key): array
    {
        return $this->db->getRows('SELECT * FROM ' . $this->table('unbound') . ' WHERE `payment_key` = ' . $this->quote($key));
    }

    public function bindWithdrawal(string $operationId, string $id): string
    {
        return $this->transaction(function () use ($operationId, $id) {
            $intent = $this->getIntent($operationId);
            $this->assertLock($intent->paymentKey());
            $other = $this->findByWithdrawalId($id);
            if ($id === '' || ($other !== null && $other->operationId() !== $operationId)
                || ($intent->withdrawalId() !== null && $intent->withdrawalId() !== $id)) {
                $this->recordState($operationId, RefundStates::REVIEW, RefundStates::NATIVE_REVIEW, 'refund_binding_conflict');
                return 'conflict';
            }
            if ($intent->withdrawalId() === $id) { return 'same'; }
            try {
                $this->execute('UPDATE ' . $this->table('intent') . ' SET `withdrawal_id` = ' . $this->quote($id)
                    . ' WHERE `operation_id` = ' . $this->quote($operationId) . ' AND `withdrawal_id` IS NULL');
            } catch (\Throwable $error) {
                $other = $this->findByWithdrawalId($id);
                if ($other === null || $other->operationId() === $operationId) { throw $error; }
                $this->recordState($operationId, RefundStates::REVIEW, RefundStates::NATIVE_REVIEW, 'refund_binding_conflict');
                return 'conflict';
            }
            $row = $this->db->getRow('SELECT * FROM ' . $this->table('intent') . ' WHERE `operation_id` = ' . $this->quote($operationId));
            if ($row === null || $row['withdrawal_id'] !== $id) { throw new \RuntimeException('refund_binding_conflict'); }
            $this->update($intent, array('withdrawal_id' => $id));
            return 'bound';
        });
    }

    public function recordObservation(string $operationId, VerifiedRefund $snapshot): void
    {
        $this->transaction(function () use ($operationId, $snapshot) {
            $intent = $this->getIntent($operationId);
            $pin = $intent->observation();
            if ($snapshot->withdrawalId() !== $intent->withdrawalId() || $snapshot->invoiceId() !== $intent->invoiceId()
                || $snapshot->projectId() !== $intent->projectId() || $snapshot->environment() !== $intent->environment()
                || $snapshot->mode() !== $intent->mode() || $snapshot->externalOrderId() !== $intent->externalOrderId()
                || ($pin !== null && (!AmountGuard::amountsEqual($pin->principalDecimal(), $snapshot->principalDecimal())
                    || $pin->currency() !== $snapshot->currency() || $pin->network() !== $snapshot->network() || !\Paymos\Plugin\RefundRecipientIdentity::same($pin, $snapshot)))) {
                throw new \RuntimeException('refund_observation_conflict');
            }
            $this->update($intent, array('observation' => $snapshot->toArray()));
        });
    }

    public function recordProjection(string $operationId, NativeProjectionResult $result): void
    {
        $this->transaction(function () use ($operationId, $result) {
            $intent = $this->getIntent($operationId);
            $pin = $intent->observation();
            $draft = $intent->fullDraft();
            $saved = $intent->toArray()['projection'];
            if ($result->kind() !== NativeProjectionResult::EXACT_MATCH || $result->mode() !== $intent->mode()
                || !$result->nativeId() || $pin === null || $pin->status() !== 'completed'
                || ($draft !== null && ($draft['amount_minor'] !== $result->nativeAmountMinor() || $draft['currency'] !== $result->nativeCurrency()))
                || ($draft === null && ($result->nativeAmountMinor() !== null || $result->nativeCurrency() !== null
                    || !AmountGuard::amountsEqual($pin->principalDecimal(), $result->cryptoObservationAmount())
                    || $result->cryptoAsset() !== $pin->currency() . '/' . $pin->network()))
                || ($saved !== null && $saved !== $result->toArray())) {
                throw new \RuntimeException('refund_projection_conflict');
            }
            $this->update($intent, array('projection' => $result->toArray()));
        });
    }

    public function recordState(string $operationId, string $cashState, string $projectionState, ?string $reason): void
    {
        $this->transaction(function () use ($operationId, $cashState, $projectionState, $reason) {
            $this->update($this->getIntent($operationId), array('cash_state' => $cashState,
                'projection_state' => $projectionState, 'review_reason' => $reason));
        });
    }

    public function claimEffect(string $operationId, string $kind, int $generation): EffectClaim
    {
        if ($this->inTransaction) { throw new \RuntimeException('refund_effect_requires_commit'); }
        return $this->transaction(function () use ($operationId, $kind, $generation) {
            $intent = $this->getIntent($operationId);
            $effects = $intent->toArray()['effects'];
            $key = $kind . ':' . $generation;
            if (isset($effects[$key]) && $effects[$key] !== RefundStates::EFFECT_PENDING) {
                return new EffectClaim(EffectClaim::ALREADY_STARTED);
            }
            if ($intent->cashState() !== 'completed' || $intent->projectionState() !== 'applied') {
                throw new \RuntimeException('refund_effect_not_ready');
            }
            $effects[$key] = RefundStates::EFFECT_STARTED;
            $this->update($intent, array('effects' => $effects));
            return new EffectClaim(EffectClaim::CLAIMED);
        });
    }

    public function recordEffectOutcome(string $operationId, string $kind, int $generation, string $state): void
    {
        $this->transaction(function () use ($operationId, $kind, $generation, $state) {
            $intent = $this->getIntent($operationId);
            $effects = $intent->toArray()['effects'];
            $key = $kind . ':' . $generation;
            if (($effects[$key] ?? null) !== RefundStates::EFFECT_STARTED
                || !in_array($state, array('delivered', 'unknown', 'failed_no_effect', 'skipped'), true)) {
                throw new \RuntimeException('refund_effect_conflict');
            }
            $effects[$key] = $state;
            $this->update($intent, array('effects' => $effects));
        });
    }

    public function listDue(\DateTimeImmutable $now, int $limit, ?string $cursor): RefundIntentPage
    {
        $after = '';
        $limit = max(1, min(100, $limit));
        if ($cursor !== null) {
            $parts = json_decode((string) base64_decode($cursor, true), true);
            if (!is_array($parts) || count($parts) !== 2 || !is_int($parts[0]) || !is_string($parts[1])) {
                throw new \InvalidArgumentException('refund_invalid_cursor');
            }
            $after = ' AND (`next_due` > ' . $parts[0] . ' OR (`next_due` = ' . $parts[0]
                . ' AND `operation_id` > ' . $this->quote($parts[1]) . '))';
        }
        $rows = $this->db->getRows('SELECT * FROM ' . $this->table('intent') . ' WHERE `next_due` <= '
            . $now->getTimestamp() . $after . ' ORDER BY `next_due`,`operation_id` LIMIT ' . ($limit + 1));
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = count($rows) ? $rows[count($rows) - 1] : null;
        return new RefundIntentPage(array_map(function ($row) { return new RefundIntent($this->decode($row['payload'])); }, $rows),
            $more && $last !== null ? base64_encode($this->encode(array((int) $last['next_due'], $last['operation_id']))) : null);
    }

    public function schedule(string $operationId): void
    {
        $this->transaction(function () use ($operationId) {
            $intent = $this->getIntent($operationId);
            $row = $this->db->getRow('SELECT * FROM ' . $this->table('intent') . ' WHERE `operation_id` = ' . $this->quote($operationId));
            $now = $this->now()->getTimestamp();
            if ($now - (int) $row['created_at'] > 86400 && in_array($intent->cashState(), array('not_submitted', 'unknown', 'created', 'processing'), true)) {
                $this->recordState($operationId, $intent->cashState(), RefundStates::NATIVE_REVIEW, 'refund_unresolved_24h');
            }
            $delays = array(60, 120, 300, 900, 3600);
            $delay = in_array($intent->cashState(), array('completed', 'failed', 'cancelled', 'review'), true) ? 86400
                : $delays[min((int) $row['poll_count'], 4)];
            if ($intent->cashState() === 'completed' && $intent->projectionState() !== 'applied') { $delay = 60; }
            $this->execute('UPDATE ' . $this->table('intent') . ' SET `next_due` = ' . ($now + $delay)
                . ', `poll_count` = `poll_count` + 1 WHERE `operation_id` = ' . $this->quote($operationId));
        });
    }

    public function projectionRow(string $operationId): ?array
    {
        return $this->db->getRow('SELECT * FROM ' . $this->table('projection') . ' WHERE `operation_id` = ' . $this->quote($operationId));
    }

    public function saveMarker(string $operationId, string $paymentKey, string $withdrawalId, NativeProjectionResult $result, ?array $mail = null): void
    {
        $this->assertLock($paymentKey);
        if (!$this->inTransaction) { throw new \RuntimeException('refund_native_transaction_required'); }
        $this->insert('projection', array('operation_id' => $operationId, 'payment_key' => $paymentKey,
            'withdrawal_id' => $withdrawalId, 'mode' => $result->mode(), 'native_id' => $result->nativeId(),
            'result' => $this->encode($result->toArray()), 'mail' => $mail === null ? null : $this->encode($mail)));
    }

    public function database(): DbInterface { return $this->db; }
    public function inTransaction(): bool { return $this->inTransaction; }
    public function now(): \DateTimeImmutable { return call_user_func($this->clock); }
    public function table(string $name): string { return '`' . Migrations::table('paymos_refund_' . $name) . '`'; }
    public function quote(string $value): string { return "'" . $this->db->escape($value) . "'"; }

    public function execute(string $sql): void
    {
        if (!$this->db->execute($sql)) { throw new \RuntimeException('refund_database_write_failed'); }
    }

    public function encode(array $data): string
    {
        $value = json_encode($data, JSON_UNESCAPED_SLASHES);
        if ($value === false) { throw new \RuntimeException('refund_storage_encode_failed'); }
        return $value;
    }

    public function decode(string $value): array
    {
        $data = json_decode($value, true);
        if (!is_array($data)) { throw new \RuntimeException('refund_storage_corrupt'); }
        return $data;
    }

    private function insert(string $name, array $row): void
    {
        if (!$this->db->insert(Migrations::table('paymos_refund_' . $name), $row)) {
            throw new \RuntimeException('refund_database_insert_failed');
        }
    }

    private function update(RefundIntent $intent, array $values): void
    {
        $this->assertLock($intent->paymentKey());
        if (!$this->inTransaction) { throw new \RuntimeException('refund_transaction_required'); }
        $data = (new RefundIntent(array_replace($intent->toArray(), $values)))->toArray();
        $this->execute('UPDATE ' . $this->table('intent') . ' SET `payload` = ' . $this->quote($this->encode($data))
            . ' WHERE `operation_id` = ' . $this->quote($intent->operationId()));
    }
}
