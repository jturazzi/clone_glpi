<?php

/**
 * -------------------------------------------------------------------------
 * Clone Ticket plugin for GLPI - Entity dropdown AJAX endpoint
 * -------------------------------------------------------------------------
 *
 * Returns the HTML for the destination entity selector. Multi-select: one
 * propagation request can target several entities at once (see
 * PropagationBatchExecutor). The active entity is pre-selected by default so
 * plain single-destination use looks the same as before this was added.
 * -------------------------------------------------------------------------
 */

include('../../../inc/includes.php');

use GlpiPlugin\Clone\PropagationBatchExecutor;

Session::checkLoginUser();

// Check rights
if (!Session::haveRight('ticket', Ticket::ASSIGN) && !Session::haveRight('config', UPDATE)) {
    http_response_code(403);
    exit;
}

header("Content-Type: text/html; charset=UTF-8");
Html::header_nocache();

// Build a simple multi-select with all accessible entities, then enhance
// with Select2 using dropdownParent so the dropdown renders inside the modal
// and avoids the Bootstrap 5 focus-trap issue that broke Entity::dropdown().

global $DB;

$entity_ids = $_SESSION['glpiactiveentities'] ?? [];
$active     = $_SESSION['glpiactive_entity'] ?? 0;

$options = [];
if (count($entity_ids)) {
    $iterator = $DB->request([
        'FROM'  => Entity::getTable(),
        'WHERE' => ['id' => $entity_ids],
        'ORDER' => 'completename',
    ]);
    foreach ($iterator as $row) {
        $options[(int) $row['id']] = $row['completename'];
    }
}

$rand = mt_rand();
$select_id = 'plugin_clone_entity_' . $rand;
$max_targets = PropagationBatchExecutor::MAX_TARGETS;

echo '<select name="clone_entities_id[]" id="' . $select_id . '" class="form-select" multiple>';
foreach ($options as $id => $name) {
    $selected = ($id === $active) ? ' selected' : '';
    echo '<option value="' . $id . '"' . $selected . '>'
        . htmlspecialchars($name) . '</option>';
}
echo '</select>';

// Enhance with Select2 (search support, multi-select). dropdownParent
// avoids BS5 focus trap. maximumSelectionLength mirrors
// PropagationBatchExecutor::MAX_TARGETS purely as an in-UI hint; the server
// enforces the real cap independently and never trusts this.
echo '<script>';
echo '$(function() {';
echo '  var $el = $("#' . $select_id . '");';
echo '  if ($.fn.select2) {';
echo '    var $modal = $el.closest(".modal");';
echo '    $el.select2({';
echo '      dropdownParent: $modal.length ? $modal : $(document.body),';
echo '      width: "100%",';
echo '      maximumSelectionLength: ' . $max_targets . '';
echo '    });';
echo '  }';
echo '});';
echo '</script>';
