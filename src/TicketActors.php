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
