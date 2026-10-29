<?php

declare(strict_types=1);

namespace PaymosPrestaShop;

use Paymos\Client;
use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\CredentialSet;
use Paymos\Plugin\EffectClaim;
use Paymos\Plugin\NativeProjectionResult;
use Paymos\Plugin\NativeRefundProjectorInterface;
use Paymos\Plugin\RefundCoordinator;
use Paymos\Plugin\RefundIntent;
use Paymos\Plugin\RefundStates;
use Paymos\Plugin\VerifiedRefund;

final class RefundAdapter implements NativeRefundProjectorInterface
{
    private $store;
    private $native;
    private $credentials;
    private $clientFactory;

    public function __construct(RefundIntentStore $store, $native, callable $credentials, callable $clientFactory)
    {
        $this->store = $store;
        $this->native = $native;
        $this->credentials = $credentials;
        $this->clientFactory = $clientFactory;
    }

    public static function forModule($module): self
    {
        $db = new RefundDatabase();
        RefundIntentStore::install($db);
        $store = new RefundIntentStore($db);
        return new self($store, new RefundNativePlatform($store),
            static function ($environment) use ($module) { return $module->paymosRefundCredentials($environment); },
            static function ($environment) use ($module) {
                return new Client(Config::fromSettings($module->paymosSettings())->clientConfigForEnvironment($environment));
            });
    }

    /** Called only by protected admin entries; amounts never enter the provider payload. */
    public function request(int $orderId, string $mode, string $token, ?array $nativeDraft = null): RefundIntent
    {
        if (!in_array($mode, array('full', 'overpayment'), true)
            || !preg_match('/^[a-zA-Z0-9-]{16,64}$/D', $token)
            || ($mode === 'overpayment' && $nativeDraft !== null)) {
            throw new \RuntimeException('refund_request_invalid');
        }
        return $this->store->withPaymentLock('prestashop:' . $orderId, function () use ($orderId, $mode, $token, $nativeDraft) {
            $installation = $this->store->installationId();
            $intent = $this->store->findByRequestToken($installation, $token);
            if ($intent !== null) {
                if ($intent->paymentKey() !== 'prestashop:' . $orderId || $intent->mode() !== $mode
                    || ($nativeDraft !== null && $intent->fullDraft() !== $nativeDraft)) {
                    throw new \RuntimeException('refund_request_conflict');
                }
                $this->requireCapability($intent->environment(), $intent->projectId(), false);
            } else {
                $snapshot = $this->native->snapshot($orderId);
                $this->requireCapability($snapshot['environment'], $snapshot['project_id'], true);
                $this->store->assertNewActionAllowed('prestashop:' . $orderId);
                $draft = $mode === 'full' ? ($nativeDraft ?: $this->native->fullDraft($snapshot)) : null;
                $intent = new RefundIntent(array('operation_id' => RefundIntentStore::uuid(), 'request_token' => $token,
                    'installation_id' => $installation, 'platform' => 'prestashop', 'native_payment_key' => 'prestashop:' . $orderId,
                    'invoice_id' => $snapshot['invoice_id'], 'project_id' => $snapshot['project_id'],
                    'environment' => $snapshot['environment'], 'mode' => $mode, 'full_draft' => $draft,
                    'native_identity' => $snapshot));
                $reason = $this->preflight($intent);
                if ($reason !== null) { throw new \RuntimeException($reason); }
                $intent = $this->store->createIntent($intent);
            }
            $this->coordinator($intent)->submit($intent->operationId());
            $this->reconcile($intent->operationId());
            return $this->store->getIntent($intent->operationId());
        });
    }

    private function requireCapability(string $environment, string $projectId, bool $new): array
    {
        $values = call_user_func($this->credentials, $environment);
        if (!CredentialSet::hasCapability($values, 'invoice_refunds_v1')) {
            throw new \RuntimeException('refund_reconnect_required');
        }
        if (($values['project_id'] ?? '') !== $projectId) { throw new \RuntimeException('refund_project_changed'); }
        if ($new && !CredentialSet::refundsEnabled($values)) { throw new \RuntimeException('refunds_not_enabled'); }
        return $values;
    }

    public function preview(int $orderId): array
    {
        return $this->store->withPaymentLock('prestashop:' . $orderId, function () use ($orderId) {
            $snapshot = $this->native->snapshot($orderId);
            $values = $this->requireCapability($snapshot['environment'], $snapshot['project_id'], false);
            $client = call_user_func($this->clientFactory, $snapshot['environment']);
            $invoice = $client->invoices()->get($snapshot['invoice_id']);
            if (($invoice['invoice_id'] ?? '') !== $snapshot['invoice_id'] || ($invoice['project_id'] ?? '') !== $snapshot['project_id']
                || ($invoice['is_test'] ?? null) !== ($snapshot['environment'] === 'sandbox')
                || !is_array($invoice['refund_summary'] ?? null)) { throw new \RuntimeException('refund_summary_scope_invalid'); }
            $summary = $invoice['refund_summary'];
            $summary['can_refund_full'] = CredentialSet::refundsEnabled($values) && ($summary['can_refund_full'] ?? false) === true;
            $summary['can_refund_overpayment'] = CredentialSet::refundsEnabled($values) && ($summary['can_refund_overpayment'] ?? false) === true;
            return $summary;
        });
    }

    public function preflight(RefundIntent $intent): ?string
    {
        try {
            $this->requireCapability($intent->environment(), $intent->projectId(), true);
            $this->native->prepare($intent);
            $this->native->assertReady($intent->mode() === 'full');
            $snapshot = $this->native->snapshot($this->orderId($intent));
            if ($snapshot !== $intent->toArray()['native_identity']) { return 'refund_native_payment_changed'; }
            if ($snapshot['invoice_id'] !== $intent->invoiceId() || $snapshot['project_id'] !== $intent->projectId()
                || $snapshot['environment'] !== $intent->environment() || !$snapshot['paid'] || $snapshot['refunded']) {
                return 'refund_native_payment_not_full';
            }
            if ($intent->mode() === 'full' && !$this->native->validDraft($snapshot, $intent->fullDraft())) {
                return 'refund_partial_or_changed_draft';
            }
            return null;
        } catch (\RuntimeException $error) {
            return $error->getMessage();
        }
    }

    public function inspect(string $operationId): NativeProjectionResult
    {
        $intent = $this->store->getIntent($operationId);
        $this->store->assertLock($intent->paymentKey());
        $row = $this->store->projectionRow($operationId);
        if ($row !== null) {
            $result = NativeProjectionResult::fromStorage($this->store->decode($row['result']));
            if ($row['payment_key'] !== $intent->paymentKey() || $row['withdrawal_id'] !== $intent->withdrawalId()
                || $row['mode'] !== $intent->mode() || $row['mode'] !== $result->mode()
                || $row['native_id'] !== $result->nativeId() || !$this->native->matchesMarker($intent, $result)) {
                return $this->ambiguous($intent, 'refund_native_marker_conflict');
            }
            return $result;
        }
        $snapshot = $this->native->snapshot($this->orderId($intent));
        if ($snapshot !== $intent->toArray()['native_identity']) { return $this->ambiguous($intent, 'refund_native_payment_changed'); }
        return new NativeProjectionResult(NativeProjectionResult::ABSENT, $intent->mode());
    }

    public function applyCompleted(RefundIntent $intent, VerifiedRefund $refund): NativeProjectionResult
    {
        $this->store->assertLock($intent->paymentKey());
        if (!$this->store->inTransaction() || $refund->status() !== 'completed'
            || $intent->withdrawalId() !== $refund->withdrawalId() || $intent->mode() !== $refund->mode()) {
            throw new \RuntimeException('refund_projection_not_ready');
        }
        $this->native->assertReady($intent->mode() === 'full');
        if ($intent->mode() === 'overpayment') {
            $id = $this->native->cashNote($this->orderId($intent), $refund);
            $result = new NativeProjectionResult(NativeProjectionResult::EXACT_MATCH, 'overpayment', $id,
                null, null, $refund->principalDecimal(), $refund->currency() . '/' . $refund->network());
            $mail = null;
        } else {
            $projection = $this->native->applyFull($intent);
            $draft = $intent->fullDraft();
            $result = new NativeProjectionResult(NativeProjectionResult::EXACT_MATCH, 'full', $projection['id'],
                $draft['amount_minor'], $draft['currency']);
            $mail = $projection['mail'];
        }
        $this->store->saveMarker($intent->operationId(), $intent->paymentKey(), $refund->withdrawalId(), $result, $mail);
        return $result;
    }

    public function reconcile(string $operationId): string
    {
        $intent = $this->store->getIntent($operationId);
        return $this->store->withPaymentLock($intent->paymentKey(), function () use ($intent, $operationId) {
            $this->requireCapability($intent->environment(), $intent->projectId(), false);
            $this->native->prepare($intent);
            $action = $this->coordinator($intent)->reconcile($operationId);
            $current = $this->store->getIntent($operationId);
            if ($current->mode() === 'full' && $current->cashState() === 'completed' && $current->projectionState() === 'applied') {
                $this->notify($current);
            }
            return $action;
        });
    }

    private function notify(RefundIntent $intent): void
    {
        $id = $intent->operationId();
        $row = $this->store->projectionRow($id);
        if ($row === null || $row['mail'] === null) { return; }
        $claim = $this->store->claimEffect($id, 'credit_slip', 0);
        if ($claim->kind() === EffectClaim::ALREADY_STARTED) {
            if (($this->store->getIntent($id)->toArray()['effects']['credit_slip:0'] ?? null) === 'started') {
                $this->store->recordEffectOutcome($id, 'credit_slip', 0, RefundStates::EFFECT_UNKNOWN);
            }
            return;
        }
        $state = RefundStates::EFFECT_UNKNOWN;
        try {
            if ($this->native->sendMail($this->store->decode($row['mail']))) { $state = RefundStates::EFFECT_DELIVERED; }
        } catch (\Throwable $error) {
            $state = RefundStates::EFFECT_UNKNOWN;
        }
        $this->store->recordEffectOutcome($id, 'credit_slip', 0, $state);
    }

    /** The signed callback selects a stored operation; all cash facts come from GET. */
    public function callback(array $data, string $environment): void
    {
        foreach (array('withdrawal_id', 'invoice_id', 'mode', 'external_order_id') as $field) {
            if (!isset($data[$field]) || !is_string($data[$field]) || $data[$field] === '') {
                throw new \RuntimeException('refund_callback_scope_missing');
            }
        }
        if (!isset($data['is_test']) || $data['is_test'] !== ($environment === 'sandbox')) {
            throw new \RuntimeException('refund_callback_environment');
        }
        $intent = $this->store->findByExternalId($data['external_order_id']);
        $bound = $this->store->findByWithdrawalId($data['withdrawal_id']);
        if ($intent === null) { $intent = $bound; }
        if ($intent === null) {
            $mapping = $this->native->invoiceMapping($data['invoice_id']);
            if ($mapping['environment'] !== $environment
                || (isset($data['project_id']) && $data['project_id'] !== $mapping['project_id'])) {
                throw new \RuntimeException('refund_callback_scope_conflict');
            }
            $this->requireCapability($environment, $mapping['project_id'], false);
            $this->observeUnbound($data['withdrawal_id'], $data['invoice_id'], $mapping['project_id'], $environment);
            return;
        }
        if ($intent->invoiceId() !== $data['invoice_id'] || (isset($data['project_id']) && $intent->projectId() !== $data['project_id'])
            || $intent->environment() !== $environment || $intent->mode() !== $data['mode']
            || $intent->externalOrderId() !== $data['external_order_id']
            || ($intent->withdrawalId() !== null && $intent->withdrawalId() !== $data['withdrawal_id'])
            || ($bound !== null && $bound->operationId() !== $intent->operationId())) {
            throw new \RuntimeException('refund_callback_scope_conflict');
        }
        $this->reconcile($intent->operationId());
    }

    private function observeUnbound(string $id, string $invoiceId, string $projectId, string $environment): void
    {
        $mapping = $this->native->invoiceMapping($invoiceId);
        if ($mapping['project_id'] !== $projectId || $mapping['environment'] !== $environment) {
            throw new \RuntimeException('refund_unbound_scope_conflict');
        }
        $paymentKey = 'prestashop:' . $mapping['id_order'];
        $this->store->withPaymentLock($paymentKey, function () use ($id, $mapping, $paymentKey, $environment) {
            $client = call_user_func($this->clientFactory, $environment);
            $refund = VerifiedRefund::fromApi($client->refunds()->get($id), $client->invoices()->get($mapping['paymos_invoice_id']), $this->store->now());
            if ($refund->withdrawalId() !== $id || $refund->invoiceId() !== $mapping['paymos_invoice_id']
                || $refund->projectId() !== $mapping['project_id'] || $refund->environment() !== $environment) {
                throw new \RuntimeException('refund_unbound_scope_conflict');
            }
            $this->store->transaction(function () use ($id, $refund, $mapping, $paymentKey) {
                $db = $this->store->database();
                $where = ' WHERE `withdrawal_id` = ' . $this->store->quote($id);
                $old = $db->getRow('SELECT * FROM ' . $this->store->table('unbound') . $where);
                if ($old !== null) {
                    $pin = VerifiedRefund::fromStorage($this->store->decode($old['payload']));
                    foreach (array('invoiceId', 'projectId', 'environment', 'mode', 'externalOrderId', 'principalDecimal', 'currency', 'network') as $method) {
                        if ($pin->$method() !== $refund->$method()) { throw new \RuntimeException('refund_unbound_evidence_conflict'); }
                    }
                    if (!\Paymos\Plugin\RefundRecipientIdentity::same($pin, $refund)) { throw new \RuntimeException('refund_unbound_evidence_conflict'); }
                }
                $reason = $refund->mode() === 'full' ? 'refund_unbound_full_draft_missing' : 'refund_unbound_pending';
                $markerKey = 'unbound:' . $id;
                $marker = $this->store->projectionRow($markerKey);
                if ($refund->mode() === 'overpayment' && $refund->status() === 'completed') {
                    if ($marker === null) {
                        $this->native->assertReady(false);
                        $note = $this->native->cashNote((int) $mapping['id_order'], $refund);
                        $result = new NativeProjectionResult(NativeProjectionResult::EXACT_MATCH, 'overpayment', $note,
                            null, null, $refund->principalDecimal(), $refund->currency() . '/' . $refund->network());
                        $this->store->saveMarker($markerKey, $paymentKey, $id, $result);
                    }
                    $reason = null;
                } elseif (in_array($refund->status(), array('failed', 'cancelled'), true) && $marker === null && $refund->mode() === 'overpayment') {
                    $reason = null;
                } elseif ($marker !== null) { $reason = 'cash_rewound'; }
                $values = array('withdrawal_id' => $id, 'payment_key' => $paymentKey,
                    'payload' => $this->store->encode($refund->toArray()), 'review_reason' => $reason,
                    'next_due' => $this->store->now()->getTimestamp() + ($reason === 'refund_unbound_pending' ? 60 : 86400));
                if ($old === null) {
                    if (!$db->insert(Migrations::table('paymos_refund_unbound'), $values)) { throw new \RuntimeException('refund_unbound_write_failed'); }
                } else {
                    $this->store->execute('UPDATE ' . $this->store->table('unbound') . ' SET `payload` = ' . $this->store->quote($values['payload'])
                        . ', `review_reason` = ' . ($reason === null ? 'NULL' : $this->store->quote($reason)) . ', `next_due` = ' . $values['next_due'] . $where);
                }
            });
        });
    }

    public function run(): int
    {
        $count = 0;
        foreach ($this->store->listDue($this->store->now(), 100, null)->items() as $intent) {
            try {
                $this->store->withPaymentLock($intent->paymentKey(), function () use ($intent, &$count) {
                    try {
                        $this->reconcile($intent->operationId());
                        $count++;
                    } catch (\Throwable $error) {
                        $this->native->log($error->getMessage());
                    } finally {
                        $this->store->schedule($intent->operationId());
                    }
                });
            } catch (\Throwable $error) { $this->native->log($error->getMessage()); }
        }
        foreach ($this->store->database()->getRows('SELECT * FROM ' . $this->store->table('unbound')
            . ' WHERE `next_due` <= ' . $this->store->now()->getTimestamp() . ' ORDER BY `next_due`,`withdrawal_id` LIMIT 100') as $row) {
            $snapshot = VerifiedRefund::fromStorage($this->store->decode($row['payload']));
            try {
                $this->requireCapability($snapshot->environment(), $snapshot->projectId(), false);
                $this->observeUnbound($snapshot->withdrawalId(), $snapshot->invoiceId(), $snapshot->projectId(), $snapshot->environment());
            } catch (\Throwable $error) {
                $this->native->log($error->getMessage());
                try {
                    $this->store->withPaymentLock($row['payment_key'], function () use ($row) {
                        $this->store->transaction(function () use ($row) {
                            $this->store->execute('UPDATE ' . $this->store->table('unbound') . ' SET `next_due` = '
                                . ($this->store->now()->getTimestamp() + 3600) . ' WHERE `withdrawal_id` = '
                                . $this->store->quote($row['withdrawal_id']));
                        });
                    });
                } catch (\Throwable $deferError) { $this->native->log($deferError->getMessage()); }
            }
        }
        return $count;
    }

    private function coordinator(RefundIntent $intent): RefundCoordinator
    {
        return new RefundCoordinator(call_user_func($this->clientFactory, $intent->environment()), $this->store, $this);
    }

    private function orderId(RefundIntent $intent): int
    {
        if (!preg_match('/^prestashop:([1-9][0-9]*)$/D', $intent->paymentKey(), $matches)) {
            throw new \RuntimeException('refund_payment_key_invalid');
        }
        return (int) $matches[1];
    }

    private function ambiguous(RefundIntent $intent, string $reason): NativeProjectionResult
    {
        return new NativeProjectionResult(NativeProjectionResult::AMBIGUOUS, $intent->mode(), null, null, null, null, null, $reason);
    }
}
