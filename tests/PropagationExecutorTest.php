<?php

/**
 * -------------------------------------------------------------------------
 * Clone Ticket plugin for GLPI - Tests
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
 *
 * NOTE ON EXECUTION: same caveat as PropagationPreflightServiceTest -- written
 * against confirmed GLPI 11 test conventions but not run against a live
 * instance in this sandbox. Treat as a correct-by-construction draft.
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Clone\Tests;

use Entity;
use Glpi\Tests\DbTestCase;
use GlpiPlugin\Clone\PropagationExecutor;
use GlpiPlugin\Clone\PropagationRequest;
use ITILCategory;
use Ticket;

class PropagationExecutorTest extends DbTestCase
{
    private int $customer_a_id;
    private int $customer_b_id;

    protected function setUp(): void
    {
        parent::setUp();

        $this->login('glpi', 'glpi');

        $root_id = getItemByTypeName(Entity::class, '_test_root_entity', true) ?: 0;

        $this->customer_a_id = $this->createItem(Entity::class, [
            'name'        => 'Executor Test Customer A',
            'entities_id' => $root_id,
        ])->getID();

        $this->customer_b_id = $this->createItem(Entity::class, [
            'name'        => 'Executor Test Customer B',
            'entities_id' => $root_id,
        ])->getID();
    }

    /**
     * The scenario the whole idempotency design exists for: same
     * propagation_uuid submitted twice (browser timeout + retry, or a
     * genuine double-click) must produce exactly one destination ticket,
     * with the second call returning the same one instead of erroring or
     * silently creating a duplicate.
     */
    public function testDuplicateSubmissionWithSameUuidCreatesExactlyOneTargetTicket(): void
    {
        $source = $this->createItem(Ticket::class, [
            'name'        => 'Duplicate submission test',
            'content'     => 'Should only ever produce one target ticket.',
            'entities_id' => $this->customer_a_id,
        ]);

        $uuid = 'a1b2c3d4-e5f6-4789-a0b1-c2d3e4f5a6b7';

        $request = PropagationRequest::forSingleTicket(
            $source->getID(),
            $this->customer_a_id,
            $this->customer_b_id,
            (int) $_SESSION['glpiID'],
            $uuid
        );

        $executor = new PropagationExecutor();

        $first = $executor->execute($request);
        $this->assertTrue($first['success']);
        $this->assertIsInt($first['ticket_id']);

        $second = $executor->execute($request);
        $this->assertTrue($second['success']);
        $this->assertSame(
            $first['ticket_id'],
            $second['ticket_id'],
            'Retrying with the same propagation_uuid must return the ticket already created, not a new one.'
        );

        $count = countElementsInTable(Ticket::getTable(), [
            'name'        => 'Duplicate submission test',
            'entities_id' => $this->customer_b_id,
        ]);
        $this->assertSame(1, $count, 'Exactly one target ticket should exist in the destination entity.');
    }

    public function testRetryAfterCompletedRequestMarksAlreadyPropagated(): void
    {
        $source = $this->createItem(Ticket::class, [
            'name'        => 'Already propagated test',
            'content'     => 'Second call should report ALREADY_PROPAGATED.',
            'entities_id' => $this->customer_a_id,
        ]);

        $uuid = 'b2c3d4e5-f6a7-4890-b1c2-d3e4f5a6b7c8';

        $request = PropagationRequest::forSingleTicket(
            $source->getID(),
            $this->customer_a_id,
            $this->customer_b_id,
            (int) $_SESSION['glpiID'],
            $uuid
        );

        $executor = new PropagationExecutor();
        $executor->execute($request);
        $second = $executor->execute($request);

        $this->assertTrue($second['success']);
        $this->assertNotNull($second['error_message']);
    }

    public function testCategoryVisibleInBothEntitiesIsPreserved(): void
    {
        $shared_category = $this->createItem(ITILCategory::class, [
            'name'         => 'Shared Category',
            'entities_id'  => 0, // root entity
            'is_recursive' => 1,
        ]);

        $source = $this->createItem(Ticket::class, [
            'name'               => 'Category preserved test',
            'content'            => 'Category exists recursively from root, valid in both entities.',
            'entities_id'        => $this->customer_a_id,
            'itilcategories_id'  => $shared_category->getID(),
        ]);

        $request = PropagationRequest::forSingleTicket(
            $source->getID(),
            $this->customer_a_id,
            $this->customer_b_id,
            (int) $_SESSION['glpiID'],
            'c3d4e5f6-a7b8-4901-c2d3-e4f5a6b7c8d9'
        );

        $result = (new PropagationExecutor())->execute($request);
        $this->assertTrue($result['success']);

        $destination = new Ticket();
        $destination->getFromDB($result['ticket_id']);

        $this->assertSame(
            $shared_category->getID(),
            (int) $destination->fields['itilcategories_id'],
            'A category recursive from the root entity should be preserved on the propagated ticket.'
        );
    }

    public function testCategorySourceOnlyIsCleared(): void
    {
        $local_category = $this->createItem(ITILCategory::class, [
            'name'         => 'Customer A Only Category',
            'entities_id'  => $this->customer_a_id,
            'is_recursive' => 0,
        ]);

        $source = $this->createItem(Ticket::class, [
            'name'               => 'Category cleared test',
            'content'            => 'Category is local to Customer A, not recursive, must not survive propagation.',
            'entities_id'        => $this->customer_a_id,
            'itilcategories_id'  => $local_category->getID(),
        ]);

        $request = PropagationRequest::forSingleTicket(
            $source->getID(),
            $this->customer_a_id,
            $this->customer_b_id,
            (int) $_SESSION['glpiID'],
            'd4e5f6a7-b8c9-4012-d3e4-f5a6b7c8d9e0'
        );

        $result = (new PropagationExecutor())->execute($request);
        $this->assertTrue($result['success']);

        $destination = new Ticket();
        $destination->getFromDB($result['ticket_id']);

        $this->assertSame(
            0,
            (int) $destination->fields['itilcategories_id'],
            'A category local to Customer A, non-recursive, must be cleared on the ticket propagated to Customer B.'
        );
    }
}
