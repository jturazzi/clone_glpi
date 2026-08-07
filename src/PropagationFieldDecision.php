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
 * The outcome of the preflight check for a single propagated field
 * (category, location, requester, assignee, observer, group, ...).
 *
 * $reason is captured at decision time even though PR1 has no UI that shows
 * it yet, because it costs nothing to record here and is exactly what a
 * future propagation-preview screen needs to render without re-deriving it.
 */
final class PropagationFieldDecision
{
    public const PRESERVE = 'preserve';
    public const CLEAR    = 'clear';

    private function __construct(
        public readonly string $action,
        public readonly string $reason
    ) {
    }

    public static function preserve(string $reason = ''): self
    {
        return new self(self::PRESERVE, $reason);
    }

    public static function clear(string $reason = ''): self
    {
        return new self(self::CLEAR, $reason);
    }

    public function isPreserve(): bool
    {
        return $this->action === self::PRESERVE;
    }

    public function isClear(): bool
    {
        return $this->action === self::CLEAR;
    }
}
