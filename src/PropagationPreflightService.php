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

use GlpiPlugin\Clone\Actor\AssigneeValidator;
use GlpiPlugin\Clone\Actor\GroupValidator;
use GlpiPlugin\Clone\Actor\ObserverValidator;
use GlpiPlugin\Clone\Actor\RequesterValidator;

/**
 * Turns "this ticket is valid in entity A" into a decision, field by field,
 * about whether it is also valid in entity B.
 *
 * Governing invariant: a value is carried into the destination only when it
 * is demonstrably valid there -- never merely because the same row ID
 * exists in both entities. Every branch below either proves validity via a
 * GLPI-native check or falls back to CLEAR; none of them default to
 * PRESERVE out of convenience.
 *
 * Deliberately a pure read: takes the source ticket and a target entity,
 * returns a plan, performs no writes. That purity is what lets a future
 * preview screen (PR2) call the exact same code the executor uses, instead
 * of a second parallel implementation that can drift from it.
 */
final class PropagationPreflightService
{
    public function __construct(
        private readonly AssigneeValidator $assignee_validator = new AssigneeValidator(),
        private readonly RequesterValidator $requester_validator = new RequesterValidator(),
        private readonly ObserverValidator $observer_validator = new ObserverValidator(),
        private readonly GroupValidator $group_validator = new GroupValidator()
    ) {
    }

    public function build(\Ticket $ticket, int $target_entities_id): PropagationPlan
    {
        return new PropagationPlan(
            category: $this->checkCategory($ticket, $target_entities_id),
            location: $this->checkLocation($ticket, $target_entities_id),
            requester: $this->checkActorRole(
                $ticket,
                $target_entities_id,
                \CommonITILActor::REQUESTER,
                $this->requester_validator,
                'requester'
            ),
            assignee: $this->checkActorRole(
                $ticket,
                $target_entities_id,
                \CommonITILActor::ASSIGN,
                $this->assignee_validator,
                'assignee'
            ),
            observer: $this->checkActorRole(
                $ticket,
                $target_entities_id,
                \CommonITILActor::OBSERVER,
                $this->observer_validator,
                'observer'
            ),
            group: $this->checkGroups($ticket, $target_entities_id)
        );
    }

    private function checkCategory(\Ticket $ticket, int $target_entities_id): PropagationFieldDecision
    {
        $category_id = (int) ($ticket->fields['itilcategories_id'] ?? 0);

        if ($category_id <= 0) {
            return PropagationFieldDecision::preserve(__('No category set on source ticket.', 'clone'));
        }

        if (EntityScopedItemVisibility::isVisibleFromEntity(\ITILCategory::getTable(), $category_id, $target_entities_id)) {
            return PropagationFieldDecision::preserve(__('Category is visible in the destination entity.', 'clone'));
        }

        return PropagationFieldDecision::clear(__('Category is not visible in the destination entity.', 'clone'));
    }

    private function checkLocation(\Ticket $ticket, int $target_entities_id): PropagationFieldDecision
    {
        $location_id = (int) ($ticket->fields['locations_id'] ?? 0);

        if ($location_id <= 0) {
            return PropagationFieldDecision::preserve(__('No location set on source ticket.', 'clone'));
        }

        if (EntityScopedItemVisibility::isVisibleFromEntity(\Location::getTable(), $location_id, $target_entities_id)) {
            return PropagationFieldDecision::preserve(__('Location is visible in the destination entity.', 'clone'));
        }

        return PropagationFieldDecision::clear(__('Location is not visible in the destination entity.', 'clone'));
    }

    private function checkActorRole(
        \Ticket $ticket,
        int $target_entities_id,
        int $role,
        object $validator,
        string $label
    ): PropagationFieldDecision {
        $users_ids = TicketActors::getUserIds($ticket, $role);

        if (empty($users_ids)) {
            return PropagationFieldDecision::preserve(
                sprintf(__('No %s set on source ticket.', 'clone'), $label)
            );
        }

        foreach ($users_ids as $users_id) {
            if (!$validator->isValidInEntity($users_id, $target_entities_id)) {
                return PropagationFieldDecision::clear(
                    sprintf(__('At least one %s has no applicable rights in the destination entity.', 'clone'), $label)
                );
            }
        }

        return PropagationFieldDecision::preserve(
            sprintf(__('All %s(s) are valid in the destination entity.', 'clone'), $label)
        );
    }

    private function checkGroups(\Ticket $ticket, int $target_entities_id): PropagationFieldDecision
    {
        $groups_ids = TicketActors::getGroupIds($ticket, \CommonITILActor::ASSIGN);

        if (empty($groups_ids)) {
            return PropagationFieldDecision::preserve(__('No assigned group set on source ticket.', 'clone'));
        }

        foreach ($groups_ids as $groups_id) {
            if (!$this->group_validator->isValidInEntity($groups_id, $target_entities_id)) {
                return PropagationFieldDecision::clear(
                    __('At least one assigned group is not visible in the destination entity.', 'clone')
                );
            }
        }

        return PropagationFieldDecision::preserve(
            __('All assigned group(s) are visible in the destination entity.', 'clone')
        );
    }

}
