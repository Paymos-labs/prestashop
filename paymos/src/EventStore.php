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

    /**
     * Owner token of the reservation remember() took (A10-02). Each successful
     * remember() mints a fresh random token; commit()/release() match it in
     * their WHERE clause, so a delivery whose reservation expired (and whose
     * event id was taken over by a newer delivery) can no longer delete or
     * commit the successor's row.
     *
     * @var string
     */
    private $pendingOwnerToken = '';

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

        $ownerToken = bin2hex(random_bytes(16));

        $inserted = $this->db->insert(Migrations::table(Migrations::EVENTS_TABLE), array(
            'event_id' => $eventId,
            'owner_token' => $ownerToken,
            'expires_at' => $now + self::RESERVATION_TTL_SECONDS,
            'created_at' => $now,
        ));

        if (!$inserted) {
            return false;
        }

        $this->pendingEventId = $eventId;
        $this->pendingOwnerToken = $ownerToken;
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

    /**
     * Extend the reservation to the full dedup window — but only if this store
     * instance still owns it. Zero matched rows mean a newer delivery took the
     * event id over after this reservation expired (A10-02): that successor's
     * row must stay untouched, and the commit is reported as not owned (false),
     * not as an error.
     *
     * @return bool True when this owner's row was extended; false when nothing
     *              was pending or the reservation was lost to a successor.
     */
    public function commit()
    {
        if ($this->pendingEventId === '') {
            return false;
        }

        $eventId = $this->pendingEventId;
        $ownerToken = $this->pendingOwnerToken;

        $this->db->execute('UPDATE `' . Migrations::table(Migrations::EVENTS_TABLE) . '`
            SET `expires_at` = ' . (int) (time() + $this->pendingTtlSeconds) . '
            WHERE `event_id` = \'' . $this->db->escape($eventId) . '\'
                AND `owner_token` = \'' . $this->db->escape($ownerToken) . '\'');

        // DbInterface::execute() does not report affected rows, so ownership is
        // confirmed by reading the row back: it is still ours exactly when the
        // token written by this remember() is the one in the row.
        $row = $this->db->getRow('SELECT `owner_token` FROM `' . Migrations::table(Migrations::EVENTS_TABLE) . '`
            WHERE `event_id` = \'' . $this->db->escape($eventId) . '\'');
        $stillOwner = is_array($row)
            && isset($row['owner_token'])
            && (string) $row['owner_token'] === $ownerToken;

        $this->pendingEventId = '';
        $this->pendingOwnerToken = '';
        $this->pendingTtlSeconds = 0;

        return $stillOwner;
    }

    /**
     * Drop the in-flight lock so the server can retry a failed processing run.
     * Only this owner's row is deleted: when the reservation expired and a
     * newer delivery owns the event id now, release() completes silently and
     * leaves the successor's row alone (A10-02).
     */
    public function release()
    {
        if ($this->pendingEventId === '') {
            return;
        }

        $this->db->execute('DELETE FROM `' . Migrations::table(Migrations::EVENTS_TABLE) . '`
            WHERE `event_id` = \'' . $this->db->escape($this->pendingEventId) . '\'
                AND `owner_token` = \'' . $this->db->escape($this->pendingOwnerToken) . '\'');

        $this->pendingEventId = '';
        $this->pendingOwnerToken = '';
        $this->pendingTtlSeconds = 0;
    }
}
