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
