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
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 */

use Glpi\Plugin\Hooks;

/**
 * Install the plugin
 *
 * @return boolean
 */
function plugin_clone_install()
{
    global $DB;

    $table = 'glpi_plugin_clone_propagations';

    if (!$DB->tableExists($table)) {
        $query = "CREATE TABLE `$table` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `batch_uuid` char(36) NOT NULL COMMENT 'groups rows created by a single propagation request; one row per destination',
            `source_itemtype` varchar(100) NOT NULL,
            `source_items_id` int unsigned NOT NULL,
            `target_itemtype` varchar(100) NOT NULL,
            `target_items_id` int unsigned DEFAULT NULL COMMENT 'set only once the destination item is successfully created',
            `source_entities_id` int unsigned NOT NULL,
            `target_entities_id` int unsigned NOT NULL,
            `status` varchar(20) NOT NULL DEFAULT 'pending' COMMENT 'pending, processing, completed, failed',
            `error_code` varchar(64) DEFAULT NULL,
            `error_message` text DEFAULT NULL COMMENT 'sanitised, user-facing only; full exception goes to GLPI log',
            `users_id` int unsigned NOT NULL COMMENT 'user who triggered the propagation',
            `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `date_processed` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `propagation_identity` (`batch_uuid`, `source_itemtype`, `source_items_id`, `target_entities_id`),
            KEY `source_item` (`source_itemtype`, `source_items_id`),
            KEY `target_entities_id` (`target_entities_id`),
            KEY `status` (`status`),
            KEY `users_id` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if (!$DB->doQuery($query)) {
            trigger_error("Clone plugin: failed to create table `$table`: " . $DB->error(), E_USER_WARNING);
            return false;
        }
    }

    return true;
}

/**
 * Uninstall the plugin
 *
 * @return boolean
 */
function plugin_clone_uninstall()
{
    global $DB;

    // Ledger is an audit trail of propagations already performed; dropping it
    // on uninstall is standard GLPI plugin behaviour (data lives only as long
    // as the plugin does), but it does mean the audit trail does not survive
    // an uninstall/reinstall cycle. Acceptable for PR1; revisit if this plugin
    // ever needs to preserve history across reinstalls.
    $table = 'glpi_plugin_clone_propagations';
    if ($DB->tableExists($table)) {
        $DB->doQuery("DROP TABLE `$table`");
    }

    return true;
}

/**
 * Check plugin prerequisites
 *
 * @return boolean
 */
function plugin_clone_check_prerequisites()
{
    if (version_compare(GLPI_VERSION, '11.0.0', '<')) {
        echo __('This plugin requires GLPI >= 11.0.0', 'clone');
        return false;
    }
    return true;
}

/**
 * Check plugin configuration
 *
 * @return boolean
 */
function plugin_clone_check_config()
{
    return true;
}

/**
 * Hook: Display clone button on ticket form (POST_ITEM_FORM)
 *
 * @param array $params Contains 'item' and 'options'
 */
function plugin_clone_post_item_form($params)
{
    global $CFG_GLPI;

    if (!isset($params['item'])) {
        return;
    }

    $item = $params['item'];

    // Only on existing Tickets (not new ones)
    if (!($item instanceof Ticket) || $item->isNewItem()) {
        return;
    }

    // Check rights: only supervisor (ASSIGN right) or super-admin
    if (!Session::haveRight('ticket', Ticket::ASSIGN) && !Session::haveRight('config', UPDATE)) {
        return;
    }

    $ticket_id = (int) $item->getID();
    $root_doc  = $CFG_GLPI['root_doc'] ?? '';
    $ajax_url  = $root_doc . '/plugins/clone/ajax/clone_ticket.php';
    // Use standalone=true so this token is NOT shared with other forms on the page
    $csrf      = Session::getNewCSRFToken(true);

    // Copy renamed from "Clone" to "Propagate": the button now runs the
    // controlled propagation engine, not a raw clone. Plugin key/directory
    // stays `clone` deliberately -- see hook.php's install/uninstall and
    // manifest.xml -- renaming those has a real upgrade-path cost for the
    // one 1.0.0 release already published, for no functional benefit.
    $button_title                = htmlspecialchars(__('Propagate this ticket to another entity', 'clone'), ENT_QUOTES, 'UTF-8');
    $button_label                = htmlspecialchars(__('Propagate to entity', 'clone'), ENT_QUOTES, 'UTF-8');
    $modal_title_prefix          = htmlspecialchars(__('Propagate ticket #', 'clone'), ENT_QUOTES, 'UTF-8');
    $close_label                 = htmlspecialchars(__('Close', 'clone'), ENT_QUOTES, 'UTF-8');
    $destination_entity_label    = htmlspecialchars(__('Destination entity', 'clone'), ENT_QUOTES, 'UTF-8');
    $loading_label               = htmlspecialchars(__('Loading...', 'clone'), ENT_QUOTES, 'UTF-8');
    $cancel_label                = htmlspecialchars(__('Cancel', 'clone'), ENT_QUOTES, 'UTF-8');
    $clone_label                 = htmlspecialchars(__('Propagate', 'clone'), ENT_QUOTES, 'UTF-8');
    $modal_open_error            = htmlspecialchars(__('Unable to open the propagation dialog. Check browser console.', 'clone'), ENT_QUOTES, 'UTF-8');
    $bootstrap_missing           = htmlspecialchars(__('Bootstrap is not available on this page. Please reload the page.', 'clone'), ENT_QUOTES, 'UTF-8');
    $entity_load_error           = htmlspecialchars(__('Error while loading entities.', 'clone'), ENT_QUOTES, 'UTF-8');
    $select_entity_error         = htmlspecialchars(__('Please select a destination entity.', 'clone'), ENT_QUOTES, 'UTF-8');
    $cloning_in_progress         = htmlspecialchars(__('Propagating...', 'clone'), ENT_QUOTES, 'UTF-8');
    $open_new_ticket_label       = htmlspecialchars(__('Open the new ticket', 'clone'), ENT_QUOTES, 'UTF-8');
    $unknown_error_label         = htmlspecialchars(__('Unknown error.', 'clone'), ENT_QUOTES, 'UTF-8');
    $communication_error_label   = htmlspecialchars(__('Communication error with server.', 'clone'), ENT_QUOTES, 'UTF-8');

    echo <<<HTML
    <div id="plugin-clone-container" class="plugin-clone-wrapper">
        <button type="button" 
                id="plugin-clone-btn" 
                class="btn btn-outline-primary plugin-clone-btn"
                data-ticket-id="{$ticket_id}"
                data-ajax-url="{$ajax_url}"
                data-csrf="{$csrf}"
                data-i18n-modal-title-prefix="{$modal_title_prefix}"
                data-i18n-close-label="{$close_label}"
                data-i18n-destination-entity-label="{$destination_entity_label}"
                data-i18n-loading-label="{$loading_label}"
                data-i18n-cancel-label="{$cancel_label}"
                data-i18n-clone-label="{$clone_label}"
                data-i18n-modal-open-error="{$modal_open_error}"
                data-i18n-bootstrap-missing="{$bootstrap_missing}"
                data-i18n-entity-load-error="{$entity_load_error}"
                data-i18n-select-entity-error="{$select_entity_error}"
                data-i18n-cloning-in-progress="{$cloning_in_progress}"
                data-i18n-open-new-ticket-label="{$open_new_ticket_label}"
                data-i18n-unknown-error-label="{$unknown_error_label}"
                data-i18n-communication-error-label="{$communication_error_label}"
                title="{$button_title}">
            <i class="ti ti-copy"></i> {$button_label}
        </button>
    </div>
HTML;
}
