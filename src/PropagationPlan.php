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
 * Output of PropagationPreflightService: what to do with each entity-scoped
 * field of the source item when creating it in the destination entity.
 *
 * Deliberately flat and field-specific (no generic resolver abstraction,
 * no mapping profiles). This is the "boring" PR1 version by design. See
 * PropagationPreflightService for the governing invariant: a value is never
 * carried over merely because the same ID happens to exist in both entities.
 */
final class PropagationPlan
{
    public function __construct(
        public readonly PropagationFieldDecision $category,
        public readonly PropagationFieldDecision $location,
        public readonly PropagationFieldDecision $requester,
        public readonly PropagationFieldDecision $assignee,
        public readonly PropagationFieldDecision $observer,
        public readonly PropagationFieldDecision $group
    ) {
    }
}
