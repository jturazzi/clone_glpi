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
 * Requester eligibility is a weaker bar than assignee: GLPI does not require
 * any specific ticket right to legitimately be a requester, just a presence
 * in the entity. Uses Profile_User::getUserEntities() (confirmed present,
 * src/Profile_User.php) rather than the rights-gated
 * getUserEntitiesForRight() the assignee check uses -- deliberately not
 * sharing one generic isActorValid() across roles, since collapsing these
 * two different eligibility questions into one check was exactly the wrong
 * abstraction identified during design.
 */
final class RequesterValidator
{
    public function isValidInEntity(int $users_id, int $target_entities_id): bool
    {
        if ($users_id <= 0) {
            return true;
        }

        $entities = \Profile_User::getUserEntities($users_id, true);

        return in_array($target_entities_id, $entities, false);
    }
}
