<?php

declare(strict_types=1);

namespace PaymosPrestaShop;

use Paymos\Webhook\EventStoreInterface;

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
final class EventStore implements EventStoreInterface
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
