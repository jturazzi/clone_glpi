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
 * Answers "is this row of an entity-scoped tree dropdown table (ITILCategory,
 * Location, Group, ...) usable from a given entity", using GLPI's own
 * entities_id/is_recursive semantics via the core getEntitiesRestrictCriteria()
 * helper (global alias over DbUtils, confirmed still used this way throughout
 * GLPI 11 core, e.g. Ticket.php's own search/list queries).
 */
final class EntityScopedItemVisibility
{
    public static function isVisibleFromEntity(string $table, int $items_id, int $target_entities_id): bool
    {
        global $DB;

        if ($items_id <= 0) {
            // Nothing set on the source item: nothing to invalidate.
            return true;
        }

        $criteria = ['id' => $items_id] + getEntitiesRestrictCriteria($table, '', $target_entities_id, true);

        $iterator = $DB->request([
            'COUNT' => 'cnt',
            'FROM'  => $table,
            'WHERE' => $criteria,
        ]);

        $row = $iterator->current();

        return $row !== null && (int) $row['cnt'] > 0;
    }
}
