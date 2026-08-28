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
 * Permission is hereby granted, free of charge, to any person obtaining a
 * copy of this software and associated documentation files (the
 * "Software"), to deal in the Software without restriction, including
 * without limitation the rights to use, copy, modify, merge, publish,
 * distribute, sublicense, and/or sell copies of the Software, and to
 * permit persons to whom the Software is furnished to do so, subject to
 * the following conditions:
 *
 * The above copyright notice and this permission notice shall be included
 * in all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS
 * OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
 * IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY
 * CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT,
 * TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE
 * SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Clone;

/**
 * Runs one PropagationRequest end to end: claim -> preflight -> create in
 * destination entity via the *normal* Ticket::add() pipeline -> link ->
 * mark completed. One destination = one local transaction; the claim itself
 * is deliberately outside that transaction (see PropagationLedgerRepository).
 *
 * Not transactionally perfect end to end: Ticket::add() can trigger GLPI
 * rules, notifications and other plugins' hooks, and a DB rollback cannot
 * undo an external side effect a notification or another plugin already
 * fired. GLPI's own notification dispatch is itself queue-based (a real
 * QueuedNotification table/class exists in core) which is reassuring --
 * most notification side effects are DB writes that would themselves
 * participate in and be undone by this same transaction -- but that has
 * only been confirmed at the "the mechanism exists" level, not traced
 * through every code path in NotificationEvent::raiseEvent(), and it says
 * nothing about a *third-party* plugin hook doing non-transactional
 * external I/O (a webhook call, a write to another system) during
 * Ticket::add()'s own hook firing. No amount of application-level code in
 * this class can retroactively undo that; it is a structural property of
 * wrapping any hook-firing GLPI core method in a transaction. This is
 * exactly the class of case the live integration test needs to exercise
 * (forced failure between Ticket::add() succeeding and commit) rather than
 * something resolved by more code here. What the transaction *does*
 * guarantee is that the destination ticket row and its Ticket_Ticket
 * relation are created together or not at all -- no half-created
 * propagated ticket with no audit link back to its source. That's a
 * narrower promise than "fully atomic", stated honestly
 * rather than oversold.
 */
final class PropagationExecutor
{
    public function __construct(
        private readonly PropagationPreflightService $preflight = new PropagationPreflightService(),
        private readonly PropagationLedgerRepository $ledger = new PropagationLedgerRepository()
    ) {
    }

    /**
     * @return array{success:bool, ticket_id:?int, ticket_url:?string, error_code:?string, error_message:?string}
     */
    public function execute(PropagationRequest $request): array
    {
        global $DB;

        // Re-checked here, independently of whatever the caller already
        // verified: never trust the entity dropdown, and this method may
        // eventually be called from bulk/cron/API paths that don't share
        // the ajax controller's checks.
        if (!\Session::haveAccessToEntity($request->target_entities_id)) {
            return $this->failure(PropagationError::DESTINATION_FORBIDDEN);
        }

        $source = new \Ticket();
        if (!$source->getFromDB($request->source_items_id)) {
            return $this->failure(PropagationError::SOURCE_NOT_FOUND);
        }

        $ledger_id = $this->ledger->claim($request);
        if ($ledger_id === null) {
            return $this->handleAlreadyClaimed($request);
        }

        try {
            $DB->beginTransaction();

            // Deliberately inside the try block, not between claim() and
            // here: this originally sat before the try, so an exception
            // during preflight (a real one hit live: a malformed rights
            // query in AssigneeValidator) skipped markFailed() entirely and
            // left the ledger row stuck in PROCESSING forever -- a status
            // claim() correctly refuses to ever reclaim automatically. Any
            // failure between claim() and a successful commit must go
            // through this catch block so the row always reaches a
            // terminal state.
            $plan = $this->preflight->build($source, $request->target_entities_id);

            $input = $this->buildDestinationInput($source, $request, $plan);

            $destination = new \Ticket();
            $new_id = $destination->add($input);

            if ($new_id === false || (int) $new_id <= 0) {
                throw new \RuntimeException('Ticket::add() returned false building the destination ticket.');
            }
            $new_id = (int) $new_id;

            $link = new \Ticket_Ticket();
            $linked = $link->add([
                'tickets_id_1' => $request->source_items_id,
                'tickets_id_2' => $new_id,
                'link'         => \CommonITILObject_CommonITILObject::DUPLICATE_WITH,
            ]);

            if ($linked === false) {
                throw new \RuntimeException('Ticket_Ticket relation creation failed.');
            }

            $this->ledger->markCompleted($ledger_id, $new_id);

            $DB->commit();

            return [
                'success'       => true,
                'ticket_id'     => $new_id,
                'ticket_url'    => \Ticket::getFormURLWithID($new_id),
                'error_code'    => null,
                'error_message' => null,
            ];
        } catch (\Throwable $e) {
            $DB->rollBack();

            $code = self::classify($e);
            // Full detail to the GLPI-visible error log; only the sanitised
            // code + generic translated message ever reach the ledger row.
            error_log(sprintf(
                '[plugin:clone] propagation %s (ticket %d -> entity %d) failed: %s',
                $request->batch_uuid,
                $request->source_items_id,
                $request->target_entities_id,
                $e->getMessage()
            ));

            // Runs after rollback, as its own statement: the audit record
            // must survive even though the create-and-relate work did not.
            $this->ledger->markFailed($ledger_id, $code, PropagationError::messageFor($code));

            return $this->failure($code);
        }
    }

    private function handleAlreadyClaimed(PropagationRequest $request): array
    {
        $existing = $this->ledger->find($request);

        if ($existing !== null && $existing['status'] === PropagationLedgerRepository::STATUS_COMPLETED) {
            $target_items_id = (int) $existing['target_items_id'];

            return [
                'success'       => true,
                'ticket_id'     => $target_items_id,
                'ticket_url'    => \Ticket::getFormURLWithID($target_items_id),
                'error_code'    => null,
                'error_message' => PropagationError::messageFor(PropagationError::ALREADY_PROPAGATED),
            ];
        }

        return $this->failure(PropagationError::PROPAGATION_IN_PROGRESS);
    }

    private function buildDestinationInput(\Ticket $source, PropagationRequest $request, PropagationPlan $plan): array
    {
        $input = [
            'entities_id'     => $request->target_entities_id,
            'name'            => $source->fields['name'],
            'content'         => $source->fields['content'],
            'urgency'         => $source->fields['urgency'],
            'impact'          => $source->fields['impact'],
            'type'            => $source->fields['type'],
            'requesttypes_id' => $source->fields['requesttypes_id'] ?? 0,
            // Deliberately NOT copying priority, SLA or OLA: passing a
            // pre-set SLA into Ticket::add() prevents the destination
            // entity's own business rules from (re)computing it. Letting
            // Ticket::add() run its normal pipeline -- the same one a human
            // filling out the form in the destination entity would hit --
            // is the whole point of routing through it instead of a raw
            // row clone. Same reasoning applies to any other rule-derived
            // field: don't carry the resolved value, let the destination
            // entity resolve it itself.
        ];

        if ($plan->category->isPreserve()) {
            $input['itilcategories_id'] = $source->fields['itilcategories_id'];
        }

        if ($plan->location->isPreserve()) {
            $input['locations_id'] = $source->fields['locations_id'];
        }

        if ($plan->requester->isPreserve()) {
            $ids = TicketActors::getUserIds($source, \CommonITILActor::REQUESTER);
            if (!empty($ids)) {
                $input['_users_id_requester'] = $ids;
            }
        }

        if ($plan->assignee->isPreserve()) {
            $ids = TicketActors::getUserIds($source, \CommonITILActor::ASSIGN);
            if (!empty($ids)) {
                $input['_users_id_assign'] = $ids;
            }
        }

        if ($plan->observer->isPreserve()) {
            $ids = TicketActors::getUserIds($source, \CommonITILActor::OBSERVER);
            if (!empty($ids)) {
                $input['_users_id_observer'] = $ids;
            }
        }

        if ($plan->group->isPreserve()) {
            $ids = TicketActors::getGroupIds($source, \CommonITILActor::ASSIGN);
            if (!empty($ids)) {
                $input['_groups_id_assign'] = $ids;
            }
        }

        return $input;
    }

    private static function classify(\Throwable $e): string
    {
        return match (true) {
            str_contains($e->getMessage(), 'Ticket_Ticket relation') => PropagationError::RELATION_CREATION_FAILED,
            str_contains($e->getMessage(), 'Ticket::add()') => PropagationError::TICKET_CREATION_FAILED,
            default => PropagationError::INTERNAL_ERROR,
        };
    }

    private function failure(string $code): array
    {
        return [
            'success'       => false,
            'ticket_id'     => null,
            'ticket_url'    => null,
            'error_code'    => $code,
            'error_message' => PropagationError::messageFor($code),
        ];
    }
}
