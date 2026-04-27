<?php

/**
 * -------------------------------------------------------------------------
 * Clone Ticket plugin for GLPI - Entity dropdown AJAX endpoint
 * -------------------------------------------------------------------------
 *
 * Returns the HTML for the entity dropdown selector.
 * -------------------------------------------------------------------------
 */

include('../../../inc/includes.php');

Session::checkLoginUser();

// Check rights
if (!Session::haveRight('ticket', Ticket::ASSIGN) && !Session::haveRight('config', UPDATE)) {
    http_response_code(403);
    exit;
}

header("Content-Type: text/html; charset=UTF-8");
Html::header_nocache();

// Build a simple <select> with all accessible entities, then enhance with
// Select2 using dropdownParent so the dropdown renders inside the modal and
// avoids the Bootstrap 5 focus-trap issue that broke Entity::dropdown().

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

echo '<select name="clone_entities_id" id="' . $select_id . '" class="form-select">';
foreach ($options as $id => $name) {
    $selected = ($id === $active) ? ' selected' : '';
    echo '<option value="' . $id . '"' . $selected . '>'
        . htmlspecialchars($name) . '</option>';
}
echo '</select>';

// Enhance with Select2 (search support) — dropdownParent avoids BS5 focus trap
echo '<script>';
echo '$(function() {';
echo '  var $el = $("#' . $select_id . '");';
echo '  if ($.fn.select2) {';
echo '    var $modal = $el.closest(".modal");';
echo '    $el.select2({ dropdownParent: $modal.length ? $modal : $(document.body), width: "100%" });';
echo '  }';
echo '});';
echo '</script>';
