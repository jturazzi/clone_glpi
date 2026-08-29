<?php

/**
 * -------------------------------------------------------------------------
 * Clone Ticket plugin for GLPI - AJAX endpoint
 * -------------------------------------------------------------------------
 *
 * Handles propagating a ticket to one or more entities via the controlled
 * propagation engine (PropagationRequest -> PropagationExecutor, fanned out
 * by PropagationBatchExecutor for more than one destination), rather than a
 * raw Ticket::clone(). See src/PropagationExecutor.php for why.
 * -------------------------------------------------------------------------
 */

include('../../../inc/includes.php');

use GlpiPlugin\Clone\EntityScopedItemVisibility;
use GlpiPlugin\Clone\PropagationBatchExecutor;
use GlpiPlugin\Clone\PropagationError;
use GlpiPlugin\Clone\Uuid;

header("Content-Type: application/json; charset=UTF-8");
Html::header_nocache();

/**
 * Relink the source ticket's linked assets (computers, phones, ...) onto
 * the newly propagated ticket -- but only the ones that are themselves
 * valid in the destination entity. An asset belonging to Customer A's
 * entity is not automatically valid on a ticket propagated into Customer
 * B's entity; the same "never copy an ID just because it exists" rule
 * PropagationPreflightService applies to category/location/actors/group
 * applies here too.
 *
 * @return array{copied:int, skipped_existing:int, skipped_not_visible:int, failed:int}
 */
function plugin_clone_relink_assets(int $source_ticket_id, int $new_ticket_id, int $target_entities_id): array
{
    global $DB;

    $copied              = 0;
    $skipped_existing     = 0;
    $skipped_not_visible  = 0;
    $failed               = 0;

    $existing_links = [];
    foreach ($DB->request(['FROM' => Item_Ticket::getTable(), 'WHERE' => ['tickets_id' => $new_ticket_id]]) as $row) {
        $existing_links[$row['itemtype'] . ':' . (int) $row['items_id']] = true;
    }

    $item_ticket = new Item_Ticket();
    foreach ($DB->request(['FROM' => Item_Ticket::getTable(), 'WHERE' => ['tickets_id' => $source_ticket_id]]) as $row) {
        $itemtype = $row['itemtype'];
        $items_id = (int) $row['items_id'];
        $key      = $itemtype . ':' . $items_id;

        if (isset($existing_links[$key])) {
            $skipped_existing++;
            continue;
        }

        if (!is_a($itemtype, CommonDBTM::class, true)) {
            $skipped_not_visible++;
            continue;
        }

        if (!EntityScopedItemVisibility::isVisibleFromEntity($itemtype::getTable(), $items_id, $target_entities_id)) {
            $skipped_not_visible++;
            continue;
        }

        $added = $item_ticket->add([
            'tickets_id'    => $new_ticket_id,
            'itemtype'      => $itemtype,
            'items_id'      => $items_id,
            '_disablenotif' => true,
        ]);

        if ($added !== false) {
            $copied++;
            $existing_links[$key] = true;
        } else {
            $failed++;
        }
    }

    return [
        'copied'              => $copied,
        'skipped_existing'    => $skipped_existing,
        'skipped_not_visible' => $skipped_not_visible,
        'failed'              => $failed,
    ];
}

/**
 * @param array{copied:int, skipped_existing:int, skipped_not_visible:int, failed:int} $links
 */
function plugin_clone_build_success_message(int $new_ticket_id, array $links): string
{
    $message = sprintf(__('Ticket successfully propagated (new ticket #%d).', 'clone'), $new_ticket_id);

    if ($links['copied'] > 0 || $links['skipped_not_visible'] > 0 || $links['failed'] > 0) {
        $message .= ' ' . sprintf(__('Linked items: %d copied', 'clone'), $links['copied']);
        if ($links['skipped_not_visible'] > 0) {
            $message .= ', ' . sprintf(__('%d not valid in destination entity', 'clone'), $links['skipped_not_visible']);
        }
        if ($links['failed'] > 0) {
            $message .= ', ' . sprintf(__('%d not copied', 'clone'), $links['failed']);
        }
        $message .= '.';
    }

    return $message;
}

try {
    // Must be authenticated
    Session::checkLoginUser();

    // Check rights: ASSIGN (supervisor) or config UPDATE (super-admin)
    if (!Session::haveRight('ticket', Ticket::ASSIGN) && !Session::haveRight('config', UPDATE)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => __('You do not have the required rights.', 'clone')]);
        exit;
    }

    // CSRF is validated by GLPI's CheckCsrfListener (kernel level) via X-Glpi-Csrf-Token header

    $ticket_id         = isset($_POST['ticket_id']) ? (int) $_POST['ticket_id'] : 0;
    $propagation_uuid  = $_POST['propagation_uuid'] ?? null;

    // Always an array client-side (clone.js sends entities_id[]), even for a
    // single destination -- one request shape, not two, since this endpoint
    // and clone.js ship together and there is no older client to stay
    // compatible with (same reasoning already applied to propagation_uuid
    // below).
    $entities_ids_raw = $_POST['entities_id'] ?? [];
    if (!is_array($entities_ids_raw)) {
        $entities_ids_raw = [$entities_ids_raw];
    }
    $entities_ids = array_values(array_unique(array_filter(
        array_map('intval', $entities_ids_raw),
        static fn (int $id): bool => $id >= 0
    )));

    // propagation_uuid is mandatory, not a soft fallback: this endpoint and
    // clone.js ship together in this same change, so there is no real
    // "older client" to be lenient for yet. A silent server-generated
    // fallback would look like it protects against duplicate submission
    // while actually not doing so (see PropagationRequest's doc-comment on
    // why the UUID must be caller-owned). If a genuinely older cached
    // client ever needs support, add the fallback back then, explicitly
    // documented as non-idempotent -- don't carry that complexity now for a
    // scenario that doesn't exist.
    if (!Uuid::isValid($propagation_uuid)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => __('Invalid or missing request identifier. Please reload the page and try again.', 'clone')]);
        exit;
    }

    if ($ticket_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => __('Invalid ticket ID.', 'clone')]);
        exit;
    }

    if (empty($entities_ids)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => __('Please select at least one destination entity.', 'clone')]);
        exit;
    }

    if (count($entities_ids) > PropagationBatchExecutor::MAX_TARGETS) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => sprintf(
                __('You can propagate to at most %d entities at once. You selected %d.', 'clone'),
                PropagationBatchExecutor::MAX_TARGETS,
                count($entities_ids)
            ),
        ]);
        exit;
    }

    // Load the source ticket
    $ticket = new Ticket();
    if (!$ticket->getFromDB($ticket_id)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => __('Ticket not found.', 'clone')]);
        exit;
    }

    // Check that user can view the source ticket (item-level ACL; entity
    // access to each *destination* is re-checked independently inside
    // PropagationExecutor, which never trusts the caller for that).
    if (!$ticket->canViewItem()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => __('You do not have access to this ticket.', 'clone')]);
        exit;
    }

    $batch   = new PropagationBatchExecutor();
    $results = $batch->execute(
        source_itemtype: Ticket::class,
        source_items_id: $ticket_id,
        source_entities_id: (int) $ticket->fields['entities_id'],
        target_entities_ids: $entities_ids,
        requesting_users_id: (int) Session::getLoginUserID(),
        batch_uuid: $propagation_uuid
    );

    $response_results = [];
    foreach ($results as $target_entities_id => $result) {
        if (!$result['success']) {
            $response_results[] = [
                'entities_id' => $target_entities_id,
                'success'     => false,
                'message'     => $result['error_message'],
            ];
            continue;
        }

        if ($result['error_code'] === PropagationError::ALREADY_PROPAGATED) {
            // Idempotent replay of an already-completed propagation: report
            // success and point back at the ticket that was created the
            // first time, without re-running asset relinking against it.
            $response_results[] = [
                'entities_id' => $target_entities_id,
                'success'     => true,
                'message'     => $result['error_message'],
                'new_id'      => $result['ticket_id'],
                'ticket_url'  => $result['ticket_url'],
            ];
            continue;
        }

        $links = plugin_clone_relink_assets($ticket_id, (int) $result['ticket_id'], $target_entities_id);

        $response_results[] = [
            'entities_id' => $target_entities_id,
            'success'     => true,
            'message'     => plugin_clone_build_success_message((int) $result['ticket_id'], $links),
            'new_id'      => $result['ticket_id'],
            'ticket_url'  => $result['ticket_url'],
            'links'       => $links,
        ];
    }

    // Top-level success means "the request was valid and was processed",
    // not "every destination succeeded" -- a bulk request partially
    // succeeding is a normal, expected outcome, not a server error. Each
    // destination's own outcome lives in results[].success.
    echo json_encode([
        'success' => true,
        'results' => $response_results,
    ]);
} catch (\Throwable $e) {
    error_log('[plugin:clone] unhandled ajax error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => __('An unexpected error occurred. Check the GLPI log for details.', 'clone')]);
}
