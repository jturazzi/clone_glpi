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
