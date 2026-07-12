<?php

declare(strict_types=1);

use MakerfolioArch\Database;
use MakerfolioArch\WebhookHandler;
use PHPUnit\Framework\TestCase;

/**
 * ARCHITECTURE.md §4 — the INSERT-first webhook idempotency contract:
 * exactly-once handling across duplicate deliveries and
 * crashed-mid-flight retries, with side effects only after commit.
 */
final class WebhookDedupTest extends TestCase
{
    private Database $db;
    private WebhookHandler $webhook;
    private int $handlerRuns = 0;
    private int $mailsSent = 0;

    protected function setUp(): void
    {
        $this->db = toy_platform_db();
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS invoices (id TEXT PRIMARY KEY, paid INTEGER NOT NULL DEFAULT 0)'
        );
        $this->db->query('INSERT INTO invoices (id, paid) VALUES (?, 0)', ['in_1']);
        $this->webhook = new WebhookHandler($this->db);
        $this->handlerRuns = 0;
        $this->mailsSent = 0;
    }

    private function markPaid(): callable
    {
        return function (Database $db): void {
            $this->handlerRuns++;
            $db->query('UPDATE invoices SET paid = 1 WHERE id = ?', ['in_1']);
        };
    }

    private function sendMail(): callable
    {
        return function (): void {
            $this->mailsSent++;
        };
    }

    public function test_first_delivery_processes_stamps_and_mails(): void
    {
        $outcome = $this->webhook->handle('evt_1', 'invoice.paid', $this->markPaid(), $this->sendMail());

        self::assertSame(WebhookHandler::PROCESSED, $outcome);
        self::assertSame(1, $this->handlerRuns);
        self::assertSame(1, $this->mailsSent);

        $row = $this->db->fetchOne('SELECT processed_at FROM billing_events WHERE event_id = ?', ['evt_1']);
        self::assertNotNull($row['processed_at'], 'handler + stamp commit together');
    }

    public function test_duplicate_delivery_short_circuits_without_rerunning_the_handler(): void
    {
        $this->webhook->handle('evt_1', 'invoice.paid', $this->markPaid(), $this->sendMail());
        $outcome = $this->webhook->handle('evt_1', 'invoice.paid', $this->markPaid(), $this->sendMail());

        self::assertSame(WebhookHandler::DUPLICATE, $outcome);
        self::assertSame(1, $this->handlerRuns, 'a stamped event must never re-run');
        self::assertSame(1, $this->mailsSent, 'no duplicate mail on redelivery');
    }

    public function test_crashed_mid_flight_retry_reruns_the_handler_exactly_once_overall(): void
    {
        // First delivery: handler throws → transaction rolls back →
        // ledger row exists UNSTAMPED and no state change committed.
        $exploded = false;
        try {
            $this->webhook->handle('evt_1', 'invoice.paid', function (Database $db): void {
                $db->query('UPDATE invoices SET paid = 1 WHERE id = ?', ['in_1']);
                throw new RuntimeException('crash mid-handle');
            }, $this->sendMail());
        } catch (RuntimeException) {
            $exploded = true;
        }
        self::assertTrue($exploded);
        self::assertSame(0, $this->mailsSent, 'after-commit work must not run for a failed handler');
        self::assertSame(
            0,
            (int) $this->db->fetchOne('SELECT paid FROM invoices WHERE id = ?', ['in_1'])['paid'],
            'the rolled-back state change must not be visible'
        );
        $row = $this->db->fetchOne('SELECT processed_at FROM billing_events WHERE event_id = ?', ['evt_1']);
        self::assertNotNull($row, 'the INSERT-first dedup row survives the crash');
        self::assertNull($row['processed_at']);

        // Stripe retries: unstamped row → the handler runs again.
        $outcome = $this->webhook->handle('evt_1', 'invoice.paid', $this->markPaid(), $this->sendMail());

        self::assertSame(WebhookHandler::RETRIED, $outcome);
        self::assertSame(1, $this->handlerRuns);
        self::assertSame(1, $this->mailsSent);
        self::assertSame(
            1,
            (int) $this->db->fetchOne('SELECT paid FROM invoices WHERE id = ?', ['in_1'])['paid']
        );
    }
}
