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
 */

use Glpi\Plugin\Hooks;

define('PLUGIN_CLONE_VERSION', '1.4.0');

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
        'license'      => 'MIT',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => '11.0',
                'max' => '12.0',
            ],
        ],
    ];
}
