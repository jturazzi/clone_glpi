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
 * Shared read access to a ticket's actor rows (Ticket_User: tickets_id,
 * users_id, type / Group_Ticket: tickets_id, groups_id, type -- both
 * confirmed against GLPI 11 core). Used by both PropagationPreflightService
 * (to decide preserve/clear) and PropagationExecutor (to build the actual
 * _users_id_* / _groups_id_* input once a field is decided PRESERVE) so the
 * two don't carry two separately-maintained copies of the same query.
 */
final class TicketActors
{
    /**
     * @return int[]
     */
    public static function getUserIds(\Ticket $ticket, int $role): array
    {
        global $DB;

        $users_ids = [];
        $iterator = $DB->request([
            'FROM'  => \Ticket_User::getTable(),
            'WHERE' => [
                'tickets_id' => $ticket->getID(),
                'type'       => $role,
            ],
        ]);

        foreach ($iterator as $row) {
            $users_ids[] = (int) $row['users_id'];
        }

        return $users_ids;
    }

    /**
     * @return int[]
     */
    public static function getGroupIds(\Ticket $ticket, int $role): array
    {
        global $DB;

        $groups_ids = [];
        $iterator = $DB->request([
            'FROM'  => \Group_Ticket::getTable(),
            'WHERE' => [
                'tickets_id' => $ticket->getID(),
                'type'       => $role,
            ],
        ]);

        foreach ($iterator as $row) {
            $groups_ids[] = (int) $row['groups_id'];
        }

        return $groups_ids;
    }
}
