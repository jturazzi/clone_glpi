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
