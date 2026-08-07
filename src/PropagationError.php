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
 * Stable, storable error codes for the propagation ledger. The ledger's
 * error_message column must never hold a raw exception message -- exceptions
 * can carry SQL fragments, file paths, or ticket content, and the ledger is
 * meant to be readable by any admin with access to the batch view, not just
 * whoever is tailing the GLPI log. Full exception detail goes to GLPI's own
 * error log via error_log()/trigger_error(); only the code + a translated,
 * generic message land in the ledger row.
 */
final class PropagationError
{
    public const ACTOR_NOT_VALID          = 'ACTOR_NOT_VALID';
    public const CATEGORY_NOT_VISIBLE     = 'CATEGORY_NOT_VISIBLE';
    public const DESTINATION_FORBIDDEN    = 'DESTINATION_FORBIDDEN';
    public const SOURCE_NOT_FOUND         = 'SOURCE_NOT_FOUND';
    public const TICKET_CREATION_FAILED   = 'TICKET_CREATION_FAILED';
    public const RELATION_CREATION_FAILED = 'RELATION_CREATION_FAILED';
    public const ALREADY_PROPAGATED       = 'ALREADY_PROPAGATED';
    public const PROPAGATION_IN_PROGRESS  = 'PROPAGATION_IN_PROGRESS';
    public const INTERNAL_ERROR           = 'INTERNAL_ERROR';

    public static function messageFor(string $code): string
    {
        return match ($code) {
            self::ACTOR_NOT_VALID => __('One or more actors are not valid in the destination entity.', 'clone'),
            self::CATEGORY_NOT_VISIBLE => __('The ticket category is not visible in the destination entity.', 'clone'),
            self::DESTINATION_FORBIDDEN => __('You do not have access to the destination entity.', 'clone'),
            self::SOURCE_NOT_FOUND => __('The source ticket could not be found.', 'clone'),
            self::TICKET_CREATION_FAILED => __('The destination ticket could not be created.', 'clone'),
            self::RELATION_CREATION_FAILED => __('The ticket was created but the source/destination link could not be recorded.', 'clone'),
            self::ALREADY_PROPAGATED => __('This ticket was already propagated to this entity.', 'clone'),
            self::PROPAGATION_IN_PROGRESS => __('This propagation is already in progress.', 'clone'),
            default => __('An unexpected error occurred. Check the GLPI log for details.', 'clone'),
        };
    }
}
