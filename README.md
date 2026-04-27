# Clone Ticket — GLPI Plugin

Clone an existing GLPI ticket to another entity with a single click.

## Features

- Adds a **"Clone to another entity"** button on every ticket form
- Opens a modal with a searchable entity dropdown (Select2)
- Clones the ticket (including linked items such as computers, phones, etc.) to the selected entity
- Displays a direct link to the newly created ticket after cloning

## Requirements

| Requirement | Version |
|-------------|---------|
| GLPI        | ≥ 11.0, < 12.0 |
| PHP         | As required by GLPI 11 |

No additional PHP extensions or database tables are needed.

## Installation

1. Download or clone this repository into `<GLPI_ROOT>/plugins/clone/`.
2. Navigate to **Setup > Plugins** in GLPI.
3. Locate **Clone Ticket** and click **Install**, then **Enable**.

```
cd /var/www/glpi/plugins
git clone https://github.com/jturazzi/clone_glpi clone
```

## Usage

1. Open an existing ticket.
2. Click the **"Clone to another entity"** button (visible to supervisors and super-admins).
3. Select the destination entity from the dropdown.
4. Click **Clone** — the plugin creates the new ticket and provides a link to it.

## Permissions

The clone button is displayed only to users who have at least one of the following rights:

- **Ticket → Assign** (supervisors)
- **Configuration → Update** (super-admins)

## File Structure

```
clone/
├── hook.php                        # Install/uninstall hooks & POST_ITEM_FORM hook
├── setup.php                       # Plugin registration (version, hooks, assets)
├── ajax/
│   ├── clone_ticket.php            # AJAX endpoint – performs the clone
│   └── get_entity_dropdown.php     # AJAX endpoint – returns entity <select>
├── locales/
│   ├── en_GB.po                    # English translations
│   └── fr_FR.po                    # French translations
└── public/
    ├── css/
    │   └── clone.css               # Button & modal styles
    └── js/
        └── clone.js                # Client-side logic (modal, fetch, Select2)
```

## Translations

The plugin ships with English (`en_GB`) and French (`fr_FR`) locales. To add a new language, create the corresponding `.po` file in `locales/` and compile it to `.mo` with `msgfmt`:

```bash
msgfmt locales/fr_FR.po -o locales/fr_FR.mo
```

## License

This plugin is distributed under the [GNU General Public License v3.0](https://www.gnu.org/licenses/gpl-3.0.html).

## Author

**Jérémy TURAZZI**
