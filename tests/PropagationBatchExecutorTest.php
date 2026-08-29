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
 * NOTE ON EXECUTION: same caveat as the other test classes here -- written
 * against confirmed GLPI 11 test conventions but not run against a live
 * instance in this sandbox. Treat as a correct-by-construction draft.
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Clone\Tests;

use Entity;
use Glpi\Tests\DbTestCase;
use GlpiPlugin\Clone\PropagationBatchExecutor;
use Ticket;

class PropagationBatchExecutorTest extends DbTestCase
{
    private int $customer_a_id;
    private int $customer_b_id;
    private int $customer_c_id;

    protected function setUp(): void
    {
        parent::setUp();

        $this->login('glpi', 'glpi');

        $root_id = getItemByTypeName(Entity::class, '_test_root_entity', true) ?: 0;

        $this->customer_a_id = $this->createItem(Entity::class, [
            'name'        => 'Batch Test Customer A',
            'entities_id' => $root_id,
        ])->getID();

        $this->customer_b_id = $this->createItem(Entity::class, [
            'name'        => 'Batch Test Customer B',
            'entities_id' => $root_id,
        ])->getID();

        $this->customer_c_id = $this->createItem(Entity::class, [
            'name'        => 'Batch Test Customer C',
            'entities_id' => $root_id,
        ])->getID();
    }

    /**
     * The core bulk fan-out scenario: one ticket, three destinations, one
     * shared batch_uuid. Each destination is expected to get its own
     * ticket, and the shared batch_uuid must not be treated as a collision
     * across different target_entities_id values (see
     * PropagationLedgerRepository's unique key).
     */
    public function testFanOutToMultipleEntitiesCreatesOneTicketPerDestination(): void
    {
        $source = $this->createItem(Ticket::class, [
            'name'        => 'Bulk fan-out test',
            'content'     => 'Should produce one ticket per selected destination.',
            'entities_id' => $this->customer_a_id,
        ]);

        $uuid = 'f1a2b3c4-d5e6-4789-a0b1-c2d3e4f5a6b7';

        $results = (new PropagationBatchExecutor())->execute(
            source_itemtype: Ticket::class,
            source_items_id: $source->getID(),
            source_entities_id: $this->customer_a_id,
            target_entities_ids: [$this->customer_b_id, $this->customer_c_id],
            requesting_users_id: (int) $_SESSION['glpiID'],
            batch_uuid: $uuid
        );

        $this->assertCount(2, $results);
        $this->assertTrue($results[$this->customer_b_id]['success']);
        $this->assertTrue($results[$this->customer_c_id]['success']);
        $this->assertNotSame(
            $results[$this->customer_b_id]['ticket_id'],
            $results[$this->customer_c_id]['ticket_id'],
            'Each destination must get its own ticket, not share one.'
        );

        $this->assertSame(
            1,
            countElementsInTable(Ticket::getTable(), [
                'name'        => 'Bulk fan-out test',
                'entities_id' => $this->customer_b_id,
            ])
        );
        $this->assertSame(
            1,
            countElementsInTable(Ticket::getTable(), [
                'name'        => 'Bulk fan-out test',
                'entities_id' => $this->customer_c_id,
            ])
        );
    }

    /**
     * Retrying the exact same batch_uuid against the exact same set of
     * destinations must not create a second ticket at any of them --
     * bulk fan-out inherits PropagationExecutor's idempotency per
     * destination, it does not get its own separate contract.
     */
    public function testRetryingSameBatchIsIdempotentPerDestination(): void
    {
        $source = $this->createItem(Ticket::class, [
            'name'        => 'Bulk retry test',
            'content'     => 'Retrying the same batch must not duplicate any destination.',
            'entities_id' => $this->customer_a_id,
        ]);

        $uuid = 'a2b3c4d5-e6f7-4890-b1c2-d3e4f5a6b7c8';
        $targets = [$this->customer_b_id, $this->customer_c_id];

        $batch = new PropagationBatchExecutor();

        $first = $batch->execute(
            Ticket::class,
            $source->getID(),
            $this->customer_a_id,
            $targets,
            (int) $_SESSION['glpiID'],
            $uuid
        );

        $second = $batch->execute(
            Ticket::class,
            $source->getID(),
            $this->customer_a_id,
            $targets,
            (int) $_SESSION['glpiID'],
            $uuid
        );

        foreach ($targets as $target_entities_id) {
            $this->assertSame(
                $first[$target_entities_id]['ticket_id'],
                $second[$target_entities_id]['ticket_id'],
                'Retrying the same batch_uuid must return the same ticket for each destination, not a new one.'
            );
        }

        $this->assertSame(
            1,
            countElementsInTable(Ticket::getTable(), [
                'name'        => 'Bulk retry test',
                'entities_id' => $this->customer_b_id,
            ])
        );
    }

    /**
     * A destination the requesting user has no access to must fail on its
     * own without affecting the other destinations in the same batch --
     * one bad target should never sink the whole submission.
     */
    public function testOneForbiddenDestinationDoesNotAffectOthers(): void
    {
        $source = $this->createItem(Ticket::class, [
            'name'        => 'Partial failure test',
            'content'     => 'One invalid destination must not block the valid ones.',
            'entities_id' => $this->customer_a_id,
        ]);

        $nonexistent_entities_id = 999999;

        $results = (new PropagationBatchExecutor())->execute(
            source_itemtype: Ticket::class,
            source_items_id: $source->getID(),
            source_entities_id: $this->customer_a_id,
            target_entities_ids: [$this->customer_b_id, $nonexistent_entities_id],
            requesting_users_id: (int) $_SESSION['glpiID'],
            batch_uuid: 'b3c4d5e6-f7a8-4901-c2d3-e4f5a6b7c8d9'
        );

        $this->assertTrue($results[$this->customer_b_id]['success']);
        $this->assertFalse($results[$nonexistent_entities_id]['success']);
    }

    public function testDuplicateTargetEntityIdsAreCollapsedToOneTicket(): void
    {
        $source = $this->createItem(Ticket::class, [
            'name'        => 'Duplicate target test',
            'content'     => 'The same entity id submitted twice must not create two tickets.',
            'entities_id' => $this->customer_a_id,
        ]);

        $results = (new PropagationBatchExecutor())->execute(
            source_itemtype: Ticket::class,
            source_items_id: $source->getID(),
            source_entities_id: $this->customer_a_id,
            target_entities_ids: [$this->customer_b_id, $this->customer_b_id],
            requesting_users_id: (int) $_SESSION['glpiID'],
            batch_uuid: 'c4d5e6f7-a8b9-4012-d3e4-f5a6b7c8d9e0'
        );

        $this->assertCount(1, $results);
        $this->assertSame(
            1,
            countElementsInTable(Ticket::getTable(), [
                'name'        => 'Duplicate target test',
                'entities_id' => $this->customer_b_id,
            ])
        );
    }
}
