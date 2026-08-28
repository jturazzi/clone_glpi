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
