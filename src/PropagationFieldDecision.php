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
