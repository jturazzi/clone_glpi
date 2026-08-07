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
 * A single propagation ask: one source ITIL object, one destination entity.
 *
 * PR1 is intentionally a "batch of one" (see PropagationExecutor). The
 * batch_uuid still exists so the ledger's unique constraint and status
 * machine are the same contract PR5's bulk executor (1 source -> N entities)
 * will consume, rather than something bulk has to retrofit later.
 *
 * $batch_uuid is supplied by the caller, not generated here. Idempotency
 * only works if the *retrying party* chooses and reuses the same key across
 * attempts (the same reasoning behind Stripe-style idempotency keys). If
 * this class minted a fresh UUID on every call, a genuine retry (browser
 * timeout, double submit) would get a different key each time and the
 * ledger's unique constraint would never catch the duplicate. The browser
 * generates the UUID once per modal open and resends it unchanged on retry;
 * see public/js/clone.js and Uuid::v4() (server-side fallback for callers
 * that don't supply one).
 */
final class PropagationRequest
{
    public function __construct(
        public readonly string $source_itemtype,
        public readonly int $source_items_id,
        public readonly int $source_entities_id,
        public readonly int $target_entities_id,
        public readonly int $requesting_users_id,
        public readonly string $batch_uuid
    ) {
    }

    public static function forSingleTicket(
        int $ticket_id,
        int $source_entities_id,
        int $target_entities_id,
        int $requesting_users_id,
        string $propagation_uuid
    ): self {
        return new self(
            source_itemtype: \Ticket::class,
            source_items_id: $ticket_id,
            source_entities_id: $source_entities_id,
            target_entities_id: $target_entities_id,
            requesting_users_id: $requesting_users_id,
            batch_uuid: $propagation_uuid
        );
    }
}
