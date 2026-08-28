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

namespace GlpiPlugin\Clone\Actor;

/**
 * Is this user a legitimate ticket assignee in the destination entity?
 *
 * This is the one check in PR1 built by analogy rather than a directly
 * confirmed single GLPI primitive: there is no one canonical "is this user
 * assignable" API. CommonITILObject::canAssign()/canAssignToMe() gate the
 * *acting* user's ability to assign on Session::haveRight('ticket', UPDATE);
 * this mirrors that by checking whether the *target* user holds general
 * ticket UPDATE right or the ticket-owning right (Ticket::OWN) in the
 * destination entity, recursion-aware, via
 * Profile_User::getUserEntitiesForRight() (confirmed present in GLPI 11,
 * src/Profile_User.php).
 *
 * $rights must be a single OR'd bitmask, not an array: GLPI's own
 * implementation builds the SQL as `rights & $rights` (src/Profile_User.php,
 * getUserEntitiesForRight, ~line 719). Passing an array there produces
 * `rights & (2, 32768)`, a multi-column tuple MySQL rejects with "Operand
 * should contain 1 column(s)" -- caught by the live GLPI 11 integration run,
 * not by static review, exactly the kind of GLPI-internal calling
 * convention that can't be verified by reading the method signature alone.
 */
final class AssigneeValidator
{
    public function isValidInEntity(int $users_id, int $target_entities_id): bool
    {
        if ($users_id <= 0) {
            return true;
        }

        $entities = \Profile_User::getUserEntitiesForRight(
            $users_id,
            \Ticket::$rightname,
            UPDATE | \Ticket::OWN,
            true
        );

        return in_array($target_entities_id, $entities, false);
    }
}
