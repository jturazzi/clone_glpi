# Clone Ticket: GLPI Plugin

This plugin lets you send a GLPI ticket to another entity with one click, without dragging along a category, technician, or location that doesn't actually make sense once the ticket lands somewhere else.

## Why this exists

If you run GLPI across several entities (an MSP with a separate entity per customer, or a large organisation split into departments), you've probably needed to copy a ticket from one entity to another. The obvious way to do that is to clone the row and change `entities_id`. The problem is that a ticket which is perfectly valid in Entity A can reference things that don't exist, or shouldn't be visible, in Entity B: a category tree scoped to A, a technician who has no rights in B, a location that only makes sense in A.

This plugin checks each of those references against the destination entity before creating the new ticket, instead of copying them blindly. If a value is genuinely valid there (because it's shared, or recursive from a parent entity both sides can see), it's kept. If it isn't, it's left off, and the destination ticket is created without it rather than pointing at something broken.

## What it does

- Adds a "Propagate to entity" button on every ticket form.
- Opens a modal where you pick the destination entity.
- Shows a preview of what will happen as soon as you pick one: category, location, requester, assignee, observer, and group, each marked kept or cleared with a reason, before you commit to anything.
- Creates the ticket in the destination entity through GLPI's normal ticket creation path, so that entity's own rules (SLA assignment, business rules, and so on) get to run instead of being skipped.
- Checks category, location, requester, assignee, observer, and assigned group against the destination entity before deciding whether to keep them.
- Relinks any assets attached to the ticket, but only the ones actually visible from the destination entity.
- Links the new ticket back to the original, so there's a record of where it came from.
- Handles retries safely. If a request times out and you click again, you won't end up with two tickets.

## Requirements

| Requirement | Version |
|-------------|---------|
| GLPI        | 11.0 or later, before 12.0 |
| PHP         | Whatever GLPI 11 itself requires |

No additional PHP extensions are needed. This version does add one database table for its own propagation history (see below); it's created automatically on install and dropped on uninstall.

## Installation

1. Download or clone this repository into `<GLPI_ROOT>/plugins/clone/`.
2. Go to Setup > Plugins in GLPI.
3. Find "Clone Ticket" and click Install, then Enable.

```bash
cd /var/www/glpi/plugins
git clone https://github.com/jturazzi/clone_glpi clone
```

## Usage

1. Open an existing ticket.
2. Click "Propagate to entity" (visible to supervisors and super-admins).
3. Pick the destination entity from the dropdown. A preview appears showing what will carry over and what won't, and why.
4. Click Propagate. The plugin creates the ticket and gives you a link to it.

If a category, location, technician, requester, observer, or group on the original ticket doesn't apply in the destination entity, it's simply left off the new ticket rather than carried over incorrectly. That's exactly what the preview in step 3 tells you before you commit to it. The new ticket keeps a link back to the one it came from, so you can trace it later.

## Permissions

The button only shows up for users with at least one of these rights:

- Ticket -> Assign (supervisors)
- Configuration -> Update (super-admins)

Access to the destination entity itself is checked again on the server, regardless of what the dropdown shows.

## How propagation decides what to keep

Nothing is copied onto the new ticket just because the same database ID happens to exist in both entities. Each entity-scoped field is checked before the ticket is created:

- **Category and location** are kept if they're visible from the destination entity, directly or recursively from a parent entity. Cleared otherwise.
- **Requester, assignee, and observer** are kept if that person actually has a presence in the destination entity (and, for the assignee specifically, the right rights there).
- **Assigned group** is checked the same way, against the destination entity.
- **Linked assets** (computers, phones, and so on) are only relinked if the asset itself is visible from the destination entity. An asset belonging to one customer's entity doesn't get attached to a ticket sitting in another customer's entity.
- **SLA and priority are deliberately not copied.** The ticket is created through GLPI's normal `Ticket::add()`, so the destination entity's own business rules decide these values, the same way they would for any ticket created there directly.

## Retrying safely

If a propagation request times out or the connection drops before you see a result, clicking the button again won't create a second ticket. The browser remembers an in-progress attempt, per ticket and per destination entity, for about thirty minutes, and the server recognises a repeated attempt as the same one instead of starting over. Picking a different destination entity, or waiting longer than that, is treated as a new attempt.

## File Structure

```
clone/
├── hook.php                            # Install/uninstall hooks & POST_ITEM_FORM hook
├── setup.php                           # Plugin registration (version, hooks, assets)
├── phpunit.xml                         # Test configuration (run from a GLPI dev checkout)
├── ajax/
│   ├── clone_ticket.php                # AJAX endpoint, runs the propagation
│   ├── get_entity_dropdown.php         # AJAX endpoint, returns the entity <select>
│   └── preview_propagation.php         # AJAX endpoint, read-only preview of what will happen
├── src/                                # Propagation engine (PSR-4, GlpiPlugin\Clone\*)
│   ├── PropagationRequest.php          # One propagation ask: source ticket + destination entity
│   ├── PropagationPreflightService.php # Decides what to keep or clear, per field
│   ├── PropagationPlan.php             # The decision, per field
│   ├── PropagationExecutor.php         # Creates the ticket, links it, records the result
│   ├── PropagationLedgerRepository.php # Reads and writes the propagation history table
│   ├── PropagationError.php            # Error codes shown to admins
│   ├── EntityScopedItemVisibility.php  # Shared category/location/group visibility check
│   ├── TicketActors.php                # Reads a ticket's requesters/assignees/observers/groups
│   ├── Uuid.php                        # UUID generation and validation
│   └── Actor/                          # Per-role eligibility checks
│       ├── AssigneeValidator.php
│       ├── RequesterValidator.php
│       ├── ObserverValidator.php
│       └── GroupValidator.php
├── tests/
│   ├── PropagationPreflightServiceTest.php
│   └── PropagationExecutorTest.php
├── locales/
│   ├── en_GB.po                        # English translations
│   └── fr_FR.po                        # French translations
└── public/
    ├── css/
    │   └── clone.css                   # Button & modal styles
    └── js/
        └── clone.js                    # Client-side logic (modal, fetch, Select2, retry safety)
```

## Translations

The plugin ships with English (`en_GB`) and French (`fr_FR`) locales. To add a new language, create the corresponding `.po` file in `locales/` and compile it to `.mo` with `msgfmt`:

```bash
msgfmt locales/fr_FR.po -o locales/fr_FR.mo
```

## Running the tests

The tests under `tests/` are written against GLPI's own test conventions (`Glpi\Tests\DbTestCase`) and need a real GLPI 11 checkout to run against, with this plugin installed at `plugins/clone/` and a configured test database:

```bash
phpunit --configuration plugins/clone/phpunit.xml
```

## License

This plugin is distributed under the [GNU General Public License v3.0](https://www.gnu.org/licenses/gpl-3.0.html).

## Author

**Jérémy TURAZZI**
