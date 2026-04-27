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

define('PLUGIN_CLONE_VERSION', '1.0.0');

/**
 * Init hooks, options, and register classes
 */
function plugin_init_clone()
{
    global $PLUGIN_HOOKS;

    Plugin::loadLang('clone');

    // CSRF_COMPLIANT is deprecated in GLPI 11, but kept for backward compat
    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['clone'] = true;

    if (Plugin::isPluginActive('clone')) {
        // Add JS and CSS on all pages
        // GLPI router auto-prepends /public for non-PHP assets, so use paths relative to public/
        $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['clone'] = 'js/clone.js';
        $PLUGIN_HOOKS[Hooks::ADD_CSS]['clone'] = 'css/clone.css';

        // Hook to add clone button on ticket form
        $PLUGIN_HOOKS[Hooks::POST_ITEM_FORM]['clone'] = 'plugin_clone_post_item_form';
    }
}

/**
 * Get the name and the version of the plugin
 */
function plugin_version_clone()
{
    return [
        'name'         => __('Clone Ticket', 'clone'),
        'version'      => PLUGIN_CLONE_VERSION,
        'author'       => 'Jérémy TURAZZI',
        'license'      => 'GPLv3',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => '11.0',
                'max' => '12.0',
            ],
        ],
    ];
}
