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
 * NOTE ON EXECUTION: written against GLPI 11's confirmed test conventions
 * (Glpi\Tests\DbTestCase, DbTestCase::createItem(), DbTestCase::login()) but
 * NOT executed in this sandbox -- there is no live GLPI installation + MySQL
 * test database available here to run it against. It needs to sit at
 * <GLPI_ROOT>/plugins/clone/tests/ inside a real GLPI 11 dev checkout and be
 * run via `phpunit --configuration plugins/clone/phpunit.xml`
 * (bootstrap points at the parent installation's tests/bootstrap.php).
 * Treat this as a correct-by-construction draft to run and adjust on first
 * use, not as a verified-passing test.
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Clone\Tests;

use CommonITILActor;
use Entity;
use Glpi\Tests\DbTestCase;
use GlpiPlugin\Clone\PropagationPreflightService;
use Profile;
use Profile_User;
use Ticket;
use Ticket_User;
use User;

/**
 * This is the regression test that matters most in PR1: it reproduces the
 * exact production failure the propagation engine exists to prevent.
 *
 * Before this plugin's rewrite, a technician who happened to share the same
 * user ID between two entities would be silently kept as assignee on a
 * cross-entity clone regardless of whether they actually had any right to
 * act on tickets in the destination entity. This test asserts the fix
 * directly: recursive-profile technicians survive propagation, entity-local
 * technicians get cleared.
 */
class PropagationPreflightServiceTest extends DbTestCase
{
    private int $msp_operations_id;
    private int $customers_id;
    private int $customer_a_id;
    private int $customer_b_id;

    protected function setUp(): void
    {
        parent::setUp();

        $this->login('glpi', 'glpi');

        $root_id = getItemByTypeName(Entity::class, '_test_root_entity', true) ?: 0;

        $this->msp_operations_id = $this->createItem(Entity::class, [
            'name'            => 'MSP Operations',
            'entities_id'     => $root_id,
        ])->getID();

        $this->customers_id = $this->createItem(Entity::class, [
            'name'            => 'Customers',
            'entities_id'     => $root_id,
        ])->getID();

        $this->customer_a_id = $this->createItem(Entity::class, [
            'name'            => 'Customer A',
            'entities_id'     => $this->customers_id,
        ])->getID();

        $this->customer_b_id = $this->createItem(Entity::class, [
            'name'            => 'Customer B',
            'entities_id'     => $this->customers_id,
        ])->getID();
    }

    public function testRecursiveRootTechnicianSurvivesCrossEntityPropagation(): void
    {
        // John: profile at the *root* entity, recursive = yes.
        $john = $this->createItem(User::class, [
            'name'     => 'john.recursive.tech',
            'password' => 'GlpiTest123!',
            'password2' => 'GlpiTest123!',
        ]);

        $super_tech_profile_id = getItemByTypeName(Profile::class, 'Technician', true);

        $this->createItem(Profile_User::class, [
            'users_id'     => $john->getID(),
            'profiles_id'  => $super_tech_profile_id,
            'entities_id'  => 0, // GLPI root entity
            'is_recursive' => 1,
        ]);

        $ticket = $this->createTicketInEntity($this->customer_a_id, [
            '_users_id_assign' => $john->getID(),
        ]);

        $plan = (new PropagationPreflightService())->build($ticket, $this->customer_b_id);

        $this->assertTrue(
            $plan->assignee->isPreserve(),
            'A technician with a recursive profile at the root entity must remain assignee '
            . 'after propagation to a sibling customer entity: they genuinely have rights there.'
        );
    }

    public function testEntityLocalTechnicianIsClearedOnCrossEntityPropagation(): void
    {
        // Mary: profile only inside Customer A, not recursive.
        $mary = $this->createItem(User::class, [
            'name'      => 'mary.local.tech',
            'password'  => 'GlpiTest123!',
            'password2' => 'GlpiTest123!',
        ]);

        $tech_profile_id = getItemByTypeName(Profile::class, 'Technician', true);

        $this->createItem(Profile_User::class, [
            'users_id'     => $mary->getID(),
            'profiles_id'  => $tech_profile_id,
            'entities_id'  => $this->customer_a_id,
            'is_recursive' => 0,
        ]);

        $ticket = $this->createTicketInEntity($this->customer_a_id, [
            '_users_id_assign' => $mary->getID(),
        ]);

        $plan = (new PropagationPreflightService())->build($ticket, $this->customer_b_id);

        $this->assertTrue(
            $plan->assignee->isClear(),
            'A technician whose profile is scoped to Customer A only, non-recursive, must NOT '
            . 'remain assignee after propagation to Customer B: this is the exact bug PR1 exists to fix.'
        );
    }

    private function createTicketInEntity(int $entities_id, array $extra_input = []): Ticket
    {
        return $this->createItem(Ticket::class, array_merge([
            'name'        => 'Regression test ticket',
            'content'     => 'Created for the recursive-rights preflight regression test.',
            'entities_id' => $entities_id,
        ], $extra_input));
    }
}
