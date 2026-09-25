<?php

declare(strict_types=1);

namespace PaymosPrestaShop;

use Paymos\Webhook\CommitAwareEventStoreInterface;

/**
 * Race-proof webhook dedup backed by `paymos_webhook_event` (event_id PRIMARY
 * KEY). `remember()` does a unique-key INSERT and returns false when the row
 * already exists, so two concurrent deliveries of the same event_id can never
 * both win.
 *
 * The SDK's EventStoreInterface only defines remember(). commit()/release() are
 * the plugin-side transactional half: the row is first inserted with a short
 * reservation TTL, then commit() extends it to the full dedup window only after
 * the order mutation succeeds, while release() deletes it so a failed callback is
 * retried by the server.
 */
final class EventStore implements CommitAwareEventStoreInterface
{
    /** Reservation window before commit(); a crashed callback frees the id quickly. */
    private const RESERVATION_TTL_SECONDS = 300;

    /** @var DbInterface */
    private $db;

    /** @var string */
    private $pendingEventId = '';

    /** @var int */
    private $pendingTtlSeconds = 0;

    public function __construct(DbInterface $db)
    {
        $this->db = $db;
    }

    public function remember($eventId, $ttlSeconds)
    {
        $eventId = (string) $eventId;
        $now = time();

        $this->db->execute('DELETE FROM `' . Migrations::table(Migrations::EVENTS_TABLE) . '`
            WHERE `expires_at` < ' . (int) $now);

        $inserted = $this->db->insert(Migrations::table(Migrations::EVENTS_TABLE), array(
            'event_id' => $eventId,
            'expires_at' => $now + self::RESERVATION_TTL_SECONDS,
            'created_at' => $now,
        ));

        if (!$inserted) {
            return false;
        }

        $this->pendingEventId = $eventId;
        $this->pendingTtlSeconds = (int) $ttlSeconds;

        return true;
    }

    /**
     * Whether the event was processed and committed — as opposed to merely
     * locked by a delivery that has not finished (BUG-103: that one must be
     * answered non-2xx, or a retry arriving mid-processing marks it delivered).
     * A committed row lives past its reservation; a lock does not.
     */
    public function isCommitted($eventId)
    {
        $row = $this->db->getRow('SELECT `expires_at`, `created_at` FROM `' . Migrations::table(Migrations::EVENTS_TABLE) . '`
            WHERE `event_id` = \'' . $this->db->escape((string) $eventId) . '\'');
        if (!is_array($row)) {
            return false;
        }

        $expiresAt = isset($row['expires_at']) ? (int) $row['expires_at'] : 0;
        $createdAt = isset($row['created_at']) ? (int) $row['created_at'] : 0;

        return $expiresAt > time() && $expiresAt > $createdAt + self::RESERVATION_TTL_SECONDS;
    }

    public function commit()
    {
        if ($this->pendingEventId === '') {
            return;
        }

        $this->db->execute('UPDATE `' . Migrations::table(Migrations::EVENTS_TABLE) . '`
            SET `expires_at` = ' . (int) (time() + $this->pendingTtlSeconds) . '
            WHERE `event_id` = \'' . $this->db->escape($this->pendingEventId) . '\'');

        $this->pendingEventId = '';
        $this->pendingTtlSeconds = 0;
    }

    public function release()
    {
        if ($this->pendingEventId === '') {
            return;
        }

        $this->db->execute('DELETE FROM `' . Migrations::table(Migrations::EVENTS_TABLE) . '`
            WHERE `event_id` = \'' . $this->db->escape($this->pendingEventId) . '\'');

        $this->pendingEventId = '';
        $this->pendingTtlSeconds = 0;
    }
}
