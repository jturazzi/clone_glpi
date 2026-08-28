<?php

/**
 * -------------------------------------------------------------------------
 * Clone Ticket plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of Clone Ticket plugin for GLPI.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Clone;

/**
 * Owns all reads/writes to glpi_plugin_clone_propagations.
 *
 * The claim() contract is written for PR5 as much as for PR1: insert as
 * PENDING, then a separate atomic "UPDATE ... WHERE status = pending"
 * commits the claim to PROCESSING *before* any slow work starts. In PR1
 * that happens back-to-back inside one synchronous request; PR5's CronTask
 * will call the exact same method to pull pending rows off a queue with
 * concurrent workers. Keeping the claim as its own committed statement
 * (never inside the same transaction as ticket creation) is what makes that
 * later reuse possible without a rewrite: a transaction that gets rolled
 * back cannot be allowed to erase the fact that a claim happened.
 */
final class PropagationLedgerRepository
{
    public const TABLE = 'glpi_plugin_clone_propagations';

    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED  = 'completed';
    public const STATUS_FAILED     = 'failed';

    /**
     * Claim a propagation row for execution.
     *
     * Returns the ledger row id to execute against, or null if this exact
     * (batch_uuid, source, target) propagation is already completed or is
     * currently being processed by someone else -- the caller must treat
     * that as "already propagated" / "in progress", never redo the work.
     *
     * IMPORTANT for future callers (PR5's CronTask in particular): today
     * this method treats both PENDING and FAILED as claimable, which is a
     * PR1-appropriate convenience for a synchronous, single-attempt,
     * human-in-the-loop flow -- a user clicking "Propagate" again after a
     * failure is *already* an explicit retry decision. That is not the same
     * thing as "a background worker should automatically re-attempt every
     * FAILED row it finds." A cron-driven bulk executor harvesting PENDING
     * rows off a queue must not silently inherit "FAILED == eligible for
     * automatic reclaim" as a permanent domain rule -- retrying a failure
     * with no human decision behind it needs its own policy (backoff, max
     * attempts, etc.), not this method's current behaviour by accident. If
     * that need arrives, split this into claimPending() (PENDING only, for
     * the cron harvest loop) and retryFailed() / an explicit
     * claim(..., bool $allow_failed_retry) (human-triggered retry only) --
     * don't let PR5 quietly reuse today's claim() and inherit an
     * unreviewed auto-retry policy.
     */
    public function claim(PropagationRequest $request): ?int
    {
        global $DB;

        $existing = $this->find($request);

        if ($existing !== null) {
            if (in_array($existing['status'], [self::STATUS_COMPLETED, self::STATUS_PROCESSING], true)) {
                return null;
            }

            // Defence in depth: a FAILED row must never carry a target_items_id
            // by construction (markCompleted() is the only writer of that
            // column, and it also flips status to COMPLETED in the same call).
            // If this invariant is ever violated -- e.g. by a future code path
            // that adds partial-success reporting -- do not silently reclaim
            // and risk creating a second target ticket; surface it as "in
            // progress" instead and let a human look at the row.
            if ($existing['status'] === self::STATUS_FAILED && $existing['target_items_id'] !== null) {
                return null;
            }

            // pending (claim was interrupted before) or failed (retry): safe to reclaim.
            $DB->update(self::TABLE, [
                'status'        => self::STATUS_PROCESSING,
                'error_code'    => null,
                'error_message' => null,
            ], ['id' => $existing['id']]);

            return (int) $existing['id'];
        }

        try {
            $DB->insert(self::TABLE, [
                'batch_uuid'         => $request->batch_uuid,
                'source_itemtype'    => $request->source_itemtype,
                'source_items_id'    => $request->source_items_id,
                'target_itemtype'    => $request->source_itemtype, // PR1: same type both sides; revisit at Problem/Change (PR6)
                'source_entities_id' => $request->source_entities_id,
                'target_entities_id' => $request->target_entities_id,
                'status'             => self::STATUS_PENDING,
                'users_id'           => $request->requesting_users_id,
            ]);
            $ledger_id = (int) $DB->insertId();
        } catch (\Throwable $e) {
            // Lost a race against a concurrent identical request (same
            // batch_uuid submitted twice near-simultaneously): fall back to
            // whatever it left behind rather than surfacing a raw DB error.
            $existing = $this->find($request);
            if ($existing === null) {
                throw $e;
            }
            if (in_array($existing['status'], [self::STATUS_COMPLETED, self::STATUS_PROCESSING], true)) {
                return null;
            }
            $ledger_id = (int) $existing['id'];
        }

        $DB->update(self::TABLE, ['status' => self::STATUS_PROCESSING], [
            'id'     => $ledger_id,
            'status' => self::STATUS_PENDING,
        ]);

        return $ledger_id;
    }

    public function find(PropagationRequest $request): ?array
    {
        global $DB;

        $row = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => [
                'batch_uuid'         => $request->batch_uuid,
                'source_itemtype'    => $request->source_itemtype,
                'source_items_id'    => $request->source_items_id,
                'target_entities_id' => $request->target_entities_id,
            ],
        ])->current();

        return $row ?: null;
    }

    public function findById(int $ledger_id): ?array
    {
        global $DB;

        $row = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['id' => $ledger_id],
        ])->current();

        return $row ?: null;
    }

    public function markCompleted(int $ledger_id, int $target_items_id): void
    {
        global $DB;

        $DB->update(self::TABLE, [
            'status'          => self::STATUS_COMPLETED,
            'target_items_id' => $target_items_id,
            'date_processed'  => date('Y-m-d H:i:s'),
        ], ['id' => $ledger_id]);
    }

    /**
     * Called *after* a rollback, as its own statement, so the failure is
     * recorded even though the create-and-relate work it describes was
     * undone. error_message must already be the sanitised, translated
     * PropagationError::messageFor() text, never a raw exception message.
     */
    public function markFailed(int $ledger_id, string $error_code, string $error_message): void
    {
        global $DB;

        $DB->update(self::TABLE, [
            'status'         => self::STATUS_FAILED,
            'error_code'     => $error_code,
            'error_message'  => $error_message,
            'date_processed' => date('Y-m-d H:i:s'),
        ], ['id' => $ledger_id]);
    }
}
