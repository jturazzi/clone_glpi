<?php

/**
 * -------------------------------------------------------------------------
 * Clone Ticket plugin for GLPI - AJAX endpoint
 * -------------------------------------------------------------------------
 *
 * Handles the ticket cloning to another entity.
 * -------------------------------------------------------------------------
 */

include('../../../inc/includes.php');

header("Content-Type: application/json; charset=UTF-8");
Html::header_nocache();

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

    $ticket_id   = isset($_POST['ticket_id']) ? (int) $_POST['ticket_id'] : 0;
    $entities_id = isset($_POST['entities_id']) ? (int) $_POST['entities_id'] : -1;

    if ($ticket_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => __('Invalid ticket ID.', 'clone')]);
        exit;
    }

    if ($entities_id < 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => __('Please select a destination entity.', 'clone')]);
        exit;
    }

    // Check that user has access to the target entity
    if (!Session::haveAccessToEntity($entities_id)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => __('You do not have access to the destination entity.', 'clone')]);
        exit;
    }

    // Load the source ticket
    $ticket = new Ticket();
    if (!$ticket->getFromDB($ticket_id)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => __('Ticket not found.', 'clone')]);
        exit;
    }

    // Check that user can view the source ticket
    if (!$ticket->canViewItem()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => __('You do not have access to this ticket.', 'clone')]);
        exit;
    }

    // Clone the ticket to the target entity
    $new_id = $ticket->clone([
        'entities_id' => $entities_id,
    ]);

    if ($new_id === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => __('Error while cloning ticket.', 'clone')]);
        exit;
    }

    // Keep linked items (computers, phones, etc.) on the cloned ticket
    global $DB;
    $copied_links  = 0;
    $failed_links  = 0;
    $skipped_links = 0;

    $existing_links = [];
    $existing_it    = $DB->request([
        'FROM'  => Item_Ticket::getTable(),
        'WHERE' => ['tickets_id' => $new_id],
    ]);

    foreach ($existing_it as $row) {
        $key = $row['itemtype'] . ':' . (int) $row['items_id'];
        $existing_links[$key] = true;
    }

    $source_it = $DB->request([
        'FROM'  => Item_Ticket::getTable(),
        'WHERE' => ['tickets_id' => $ticket_id],
    ]);

    $item_ticket = new Item_Ticket();
    foreach ($source_it as $row) {
        $key = $row['itemtype'] . ':' . (int) $row['items_id'];

        if (isset($existing_links[$key])) {
            $skipped_links++;
            continue;
        }

        $added = $item_ticket->add([
            'tickets_id' => $new_id,
            'itemtype'   => $row['itemtype'],
            'items_id'   => (int) $row['items_id'],
            '_disablenotif' => true,
        ]);

        if ($added !== false) {
            $copied_links++;
            $existing_links[$key] = true;
        } else {
            $failed_links++;
        }
    }

    // Build URL to the new ticket
    $new_ticket_url = Ticket::getFormURLWithID($new_id);

    $message = sprintf(__('Ticket successfully cloned (new ticket #%d).', 'clone'), $new_id);
    if ($copied_links > 0 || $skipped_links > 0 || $failed_links > 0) {
        $message .= ' ' . sprintf(__('Linked items: %d copied', 'clone'), $copied_links);
        if ($skipped_links > 0) {
            $message .= ', ' . sprintf(__('%d already present', 'clone'), $skipped_links);
        }
        if ($failed_links > 0) {
            $message .= ', ' . sprintf(__('%d not copied', 'clone'), $failed_links);
        }
        $message .= '.';
    }

    echo json_encode([
        'success'    => true,
        'message'    => $message,
        'new_id'     => $new_id,
        'ticket_url' => $new_ticket_url,
        'links'      => [
            'copied'  => $copied_links,
            'skipped' => $skipped_links,
            'failed'  => $failed_links,
        ],
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => sprintf(__('Server error: %s', 'clone'), $e->getMessage())]);
}
