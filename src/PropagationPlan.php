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
 * Output of PropagationPreflightService: what to do with each entity-scoped
 * field of the source item when creating it in the destination entity.
 *
 * Deliberately flat and field-specific (no generic resolver abstraction,
 * no mapping profiles). This is the "boring" PR1 version by design. See
 * PropagationPreflightService for the governing invariant: a value is never
 * carried over merely because the same ID happens to exist in both entities.
 */
final class PropagationPlan
{
    public function __construct(
        public readonly PropagationFieldDecision $category,
        public readonly PropagationFieldDecision $location,
        public readonly PropagationFieldDecision $requester,
        public readonly PropagationFieldDecision $assignee,
        public readonly PropagationFieldDecision $observer,
        public readonly PropagationFieldDecision $group
    ) {
    }
}
