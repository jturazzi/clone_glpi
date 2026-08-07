# Changelog

## Unreleased

This is a rewrite of how the plugin moves a ticket between entities. The short version: instead of cloning the ticket row and hoping everything on it still makes sense in the new entity, the plugin now checks each entity-scoped field before deciding whether to keep it.

### Why

The original 1.0.0 clone worked fine as long as both entities shared the same categories, locations, technicians, and groups. Once you tried it across genuinely separate entities, which is the actual use case for an MSP or a multi-entity deployment, it could quietly attach a category or technician from the source entity that had no business being on a ticket in the destination entity. A technician with rights only in the source entity would end up assigned to a ticket they couldn't see or act on. That's the bug this release fixes.

### Added

- A propagation engine (`src/`) that checks category, location, requester, assignee, observer, and assigned group against the destination entity before creating the new ticket, instead of copying them unconditionally.
- The new ticket is created through GLPI's normal `Ticket::add()`, so the destination entity's own business rules and SLA assignment run the way they would for any ticket created there directly. Priority and SLA are no longer copied from the source ticket, for the same reason.
- Linked assets are relinked only if they're actually visible from the destination entity.
- A propagation history table (`glpi_plugin_clone_propagations`) records every attempt: which ticket, which destination entity, whether it succeeded, and why it failed if it didn't.
- The new ticket is linked back to the source ticket using GLPI 11's `CommonITILObject_CommonITILObject` relation, so a propagated ticket can be traced back to where it came from.
- Retrying a request that timed out, or clicking twice by accident, no longer creates a duplicate ticket. The browser and server agree on what counts as "the same attempt" for about thirty minutes, tracked separately per ticket and destination entity.
- A small test suite covering the scenario this whole change exists for: a technician with a recursive profile at a parent entity keeps their assignment after propagation, one whose profile is local to the source entity does not.

### Changed

- The button on the ticket form is now labelled "Propagate to entity" instead of "Clone to another entity", to describe what it actually does. The plugin's internal name and install directory (`clone`) are unchanged, so this doesn't require reinstalling.
- "Cloning in progress..." became "Propagating...".

### Removed

- The plugin no longer copies a ticket's category, location, technician, requester, observer, or assigned group to the destination entity just because the same ID exists there. Each one is checked first, and only kept if it's actually valid in the destination entity.

### Fixed

Running this against a live GLPI 11 instance, not just the test suite, turned up two real bugs:

- The assignee check was calling `Profile_User::getUserEntitiesForRight()` with an array of rights (`[UPDATE, Ticket::OWN]`). GLPI's own implementation builds that into `rights & $rights` in SQL, which expects a single value, not a list. MySQL rejected the array with "Operand should contain 1 column(s)", so propagating a ticket with an assignee failed outright. Fixed by combining the rights into one value (`UPDATE | Ticket::OWN`) before passing it in.
- That failure exposed a second, worse problem. The code that checks category, location, and actors ran before the try/catch block meant to handle failures, so when it threw, the propagation attempt never got marked as failed. It just sat there marked "processing" forever, and every retry after that was told the propagation was still in progress even though nothing was running anymore. Moved that check inside the try block so a failure there gets recorded properly and a retry can pick it back up.

### Known limitations

I kept this narrow on purpose. Here's what it doesn't do yet:

- Only Tickets are supported. Problems and Changes aren't handled.
- Only one destination entity at a time. Sending a ticket to several entities at once, useful for an incident affecting more than one customer, isn't built.
- The retry protection is time-limited on purpose (about thirty minutes). It's meant to catch accidental double-submits and network timeouts, not to permanently stop someone from deliberately propagating the same ticket to the same entity again later.
- Documents, followups, and tasks on the source ticket are not copied.
- No configuration page yet. The rights required to see the button, and the fields checked during propagation, are fixed in code.

### Status

I ran this against a live GLPI 11.0.8 instance with a real entity tree rather than trusting the test suite alone. Recursive technician rights, category and location visibility, group visibility, linked asset visibility, the native `Ticket_Ticket` relation, and duplicate-submission handling all held up. Two identical requests with the same idempotency key still only produced one destination ticket. Ready to tag once someone's reviewed it.
