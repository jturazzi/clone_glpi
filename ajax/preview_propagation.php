<?php

/**
 * -------------------------------------------------------------------------
 * Clone Ticket plugin for GLPI - Propagation preview AJAX endpoint
 * -------------------------------------------------------------------------
 *
 * Read-only: runs the exact same PropagationPreflightService the executor
 * uses, but performs no writes and touches no ledger row. Deliberately a
 * thin wrapper around that service rather than a second implementation of
 * "what will be kept" -- PropagationPreflightService::build() was built as
 * a pure function specifically so this endpoint could reuse it as-is
 * (see PropagationPreflightService's own doc-comment).
 * -------------------------------------------------------------------------
 */

include('../../../inc/includes.php');

use GlpiPlugin\Clone\PropagationFieldDecision;
use GlpiPlugin\Clone\PropagationPreflightService;

header("Content-Type: application/json; charset=UTF-8");
Html::header_nocache();

function plugin_clone_field_to_array(PropagationFieldDecision $decision): array
{
    return [
        'action' => $decision->action,
        'reason' => $decision->reason,
    ];
}

try {
    Session::checkLoginUser();

    if (!Session::haveRight('ticket', Ticket::ASSIGN) && !Session::haveRight('config', UPDATE)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => __('You do not have the required rights.', 'clone')]);
        exit;
    }

    $ticket_id   = isset($_GET['ticket_id']) ? (int) $_GET['ticket_id'] : 0;
    $entities_id = isset($_GET['entities_id']) ? (int) $_GET['entities_id'] : -1;

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

    $ticket = new Ticket();
    if (!$ticket->getFromDB($ticket_id)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => __('Ticket not found.', 'clone')]);
        exit;
    }

    if (!$ticket->canViewItem()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => __('You do not have access to this ticket.', 'clone')]);
        exit;
    }

    // Same check the executor re-runs independently before ever writing
    // anything -- never trust the entity dropdown, preview or not.
    if (!Session::haveAccessToEntity($entities_id)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => __('You do not have access to the destination entity.', 'clone')]);
        exit;
    }

    $plan = (new PropagationPreflightService())->build($ticket, $entities_id);

    echo json_encode([
        'success' => true,
        'plan'    => [
            'category'  => plugin_clone_field_to_array($plan->category),
            'location'  => plugin_clone_field_to_array($plan->location),
            'requester' => plugin_clone_field_to_array($plan->requester),
            'assignee'  => plugin_clone_field_to_array($plan->assignee),
            'observer'  => plugin_clone_field_to_array($plan->observer),
            'group'     => plugin_clone_field_to_array($plan->group),
        ],
    ]);
} catch (\Throwable $e) {
    error_log('[plugin:clone] unhandled preview error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => __('An unexpected error occurred. Check the GLPI log for details.', 'clone')]);
}
