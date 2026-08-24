/**
 * -------------------------------------------------------------------------
 * Clone Ticket plugin for GLPI
 * -------------------------------------------------------------------------
 * JavaScript: Clone button + modal entity selector
 * -------------------------------------------------------------------------
 */
(function () {
    "use strict";

    var PROPAGATION_OP_STORAGE_PREFIX = "plugin_clone_propagation_op_";
    // Comfortably longer than any real network hang/retry, short enough that
    // "the user came back later with a deliberately fresh attempt" reliably
    // falls outside it. There is no UI in PR1 for "retry vs. start over" (by
    // design -- see conversation), so this window is the only thing standing
    // in for that decision. Not keyed off modal-close: closing the modal
    // doesn't prove the in-flight fetch died with it, so treating close as
    // abandonment risks minting a second UUID -- and a second ticket -- for a
    // request that actually succeeded moments later.
    var PROPAGATION_OP_TTL_MS = 30 * 60 * 1000;
    var PROPAGATION_OP_VERSION = 1;
    var inMemoryPropagationOps = {}; // fallback if sessionStorage is unavailable

    // version is checked (not just parsed) so a future change to this
    // record's shape can discard old-format entries outright instead of
    // having to interpret them -- a tab left open across a clone.js upgrade
    // should never hand a mismatched shape to resolvePropagationUuid().
    function isValidOperation(candidate) {
        return !!candidate && candidate.version === PROPAGATION_OP_VERSION;
    }

    // Keyed by (ticket, sorted set of target entities), not ticket alone: a
    // single ticket->entity slot would let propagating the same ticket to a
    // *different* destination silently overwrite a still-relevant operation
    // record. Confirmed by test: with a ticket-only key, B -> C -> back to B
    // produced a fresh UUID for B against a slot the server has no record
    // of -- if the original B attempt had already succeeded, that reissues
    // a second ticket in B, not a caught duplicate. That failure mode is
    // worse than the accepted "2 hours later" gap because it can happen
    // within seconds of ordinary use. Bulk fan-out extends the same
    // reasoning: the key is the whole destination *set*, sorted so
    // selection order doesn't matter, because one batch_uuid is shared
    // across every destination in a submission (see
    // PropagationBatchExecutor). Picking a different set of destinations,
    // even if it overlaps the previous one, is treated as a new attempt.
    function operationStorageKey(ticketId, targetEntityIds) {
        var sorted = targetEntityIds.slice().sort(function (a, b) {
            return Number(a) - Number(b);
        });
        return PROPAGATION_OP_STORAGE_PREFIX + ticketId + "_" + sorted.join("-");
    }

    function readStoredOperation(ticketId, targetEntityIds) {
        var storageKey = operationStorageKey(ticketId, targetEntityIds);
        try {
            var raw = window.sessionStorage.getItem(storageKey);
            var parsed = raw ? JSON.parse(raw) : null;
            return isValidOperation(parsed) ? parsed : null;
        } catch (e) {
            var fallback = inMemoryPropagationOps[storageKey] || null;
            return isValidOperation(fallback) ? fallback : null;
        }
    }

    function writeStoredOperation(ticketId, targetEntityIds, operation) {
        operation.version = PROPAGATION_OP_VERSION;
        var storageKey = operationStorageKey(ticketId, targetEntityIds);
        try {
            window.sessionStorage.setItem(storageKey, JSON.stringify(operation));
        } catch (e) {
            inMemoryPropagationOps[storageKey] = operation;
        }
    }

    // Discard the operation only once every destination in the batch has
    // definitively succeeded (per the locked design: happens after success,
    // never merely because the Submit button becomes clickable again). If
    // any destination is still failed, keep the stored batch_uuid so a
    // retry click reuses it: already-succeeded destinations then come back
    // as an idempotent replay instead of a second ticket, and only the
    // failed ones actually get re-attempted (see PropagationLedgerRepository
    // claim() -- a FAILED row is safe to reclaim, a COMPLETED one is not).
    function clearStoredOperation(ticketId, targetEntityIds) {
        var storageKey = operationStorageKey(ticketId, targetEntityIds);
        try {
            window.sessionStorage.removeItem(storageKey);
        } catch (e) {
            // ignore: nothing to clean up if storage was never available
        }
        delete inMemoryPropagationOps[storageKey];
    }

    /**
     * Idempotency key for this (ticket, destination-set) submission. Reused
     * only if a record already exists for this exact set and is still
     * within the TTL window; a different set of destinations is a different
     * storage slot entirely, so it always gets its own fresh key with no
     * risk of clobbering another set's still-relevant record. Resolved at
     * first submit, not at modal open -- opening the modal and not
     * submitting reserves nothing.
     */
    function resolvePropagationUuid(ticketId, targetEntityIds) {
        var existing = readStoredOperation(ticketId, targetEntityIds);
        var now = Date.now();

        if (existing && (now - existing.createdAt) < PROPAGATION_OP_TTL_MS) {
            return existing.batchUuid;
        }

        var fresh = generateUuidV4();
        writeStoredOperation(ticketId, targetEntityIds, {
            batchUuid: fresh,
            targetEntityIds: targetEntityIds,
            createdAt: now
        });
        return fresh;
    }

    // Use event delegation on document body since the button is injected
    // dynamically by the POST_ITEM_FORM hook after page load
    document.addEventListener("click", function (e) {
        var btn = e.target.closest("#plugin-clone-btn");
        if (!btn) {
            return;
        }
        e.preventDefault();
        e.stopPropagation();
        try {
            openCloneModal(btn);
        } catch (err) {
            // Keep a visible feedback if a JS runtime error occurs
            // instead of silently doing nothing.
            var fallbackMsg = btn.getAttribute("data-i18n-modal-open-error")
                || "Unable to open the cloning dialog. Check browser console.";
            console.error("Clone Ticket plugin error:", err);
            window.alert(fallbackMsg);
        }
    });

    function openCloneModal(btn) {
        var ticketId = btn.getAttribute("data-ticket-id");
        var ajaxUrl = btn.getAttribute("data-ajax-url");
        var csrf = btn.getAttribute("data-csrf");
        // Client-side mirror of PropagationBatchExecutor::MAX_TARGETS, read
        // from the server-rendered attribute so the two can't drift apart.
        // This only improves the error message shown before submitting; the
        // server enforces the real cap independently either way.
        var maxTargets = parseInt(btn.getAttribute("data-max-targets"), 10) || 25;
        var rootDoc = (typeof CFG_GLPI !== "undefined" && CFG_GLPI.root_doc) ? CFG_GLPI.root_doc : "";
        var i18n = {
            modalTitlePrefix: btn.getAttribute("data-i18n-modal-title-prefix") || "Propagate ticket #",
            closeLabel: btn.getAttribute("data-i18n-close-label") || "Close",
            destinationEntityLabel: btn.getAttribute("data-i18n-destination-entity-label") || "Destination entity",
            loadingLabel: btn.getAttribute("data-i18n-loading-label") || "Loading...",
            cancelLabel: btn.getAttribute("data-i18n-cancel-label") || "Cancel",
            cloneLabel: btn.getAttribute("data-i18n-clone-label") || "Propagate",
            bootstrapMissing: btn.getAttribute("data-i18n-bootstrap-missing") || "Bootstrap is not available on this page. Please reload the page.",
            entityLoadError: btn.getAttribute("data-i18n-entity-load-error") || "Error while loading entities.",
            selectEntityError: btn.getAttribute("data-i18n-select-entity-error") || "Please select at least one destination entity.",
            tooManyEntitiesError: btn.getAttribute("data-i18n-too-many-entities-error") || "Too many destination entities selected.",
            cloningInProgress: btn.getAttribute("data-i18n-cloning-in-progress") || "Propagating...",
            openNewTicketLabel: btn.getAttribute("data-i18n-open-new-ticket-label") || "Open the new ticket",
            unknownErrorLabel: btn.getAttribute("data-i18n-unknown-error-label") || "Unknown error.",
            communicationErrorLabel: btn.getAttribute("data-i18n-communication-error-label") || "Communication error with server.",
            resultsSummaryLabel: btn.getAttribute("data-i18n-results-summary-label") || "destinations propagated successfully",
            previewHeading: btn.getAttribute("data-i18n-preview-heading") || "What will happen",
            previewLoading: btn.getAttribute("data-i18n-preview-loading") || "Checking what will carry over...",
            previewError: btn.getAttribute("data-i18n-preview-error") || "Could not load the preview.",
            previewKeptLabel: btn.getAttribute("data-i18n-preview-kept-label") || "Kept",
            previewClearedLabel: btn.getAttribute("data-i18n-preview-cleared-label") || "Cleared",
            previewFieldCategory: btn.getAttribute("data-i18n-preview-field-category") || "Category",
            previewFieldLocation: btn.getAttribute("data-i18n-preview-field-location") || "Location",
            previewFieldRequester: btn.getAttribute("data-i18n-preview-field-requester") || "Requester",
            previewFieldAssignee: btn.getAttribute("data-i18n-preview-field-assignee") || "Assignee",
            previewFieldObserver: btn.getAttribute("data-i18n-preview-field-observer") || "Observer",
            previewFieldGroup: btn.getAttribute("data-i18n-preview-field-group") || "Group"
        };

        // Remove any existing modal
        var existing = document.getElementById("plugin-clone-modal");
        if (existing) {
            existing.remove();
        }

        // Build Bootstrap 5 modal
        var modalHtml =
            '<div class="modal fade" id="plugin-clone-modal" tabindex="-1" aria-labelledby="plugin-clone-modal-label" aria-hidden="true">' +
            '  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">' +
            '    <div class="modal-content">' +
            '      <div class="modal-header">' +
            '        <h5 class="modal-title" id="plugin-clone-modal-label">' +
            '          <i class="ti ti-copy me-1"></i> ' + escapeHtml(i18n.modalTitlePrefix) + escapeHtml(ticketId) +
            '        </h5>' +
            '        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="' + escapeHtml(i18n.closeLabel) + '"></button>' +
            '      </div>' +
            '      <div class="modal-body">' +
            '        <div class="mb-3">' +
            '          <label class="form-label fw-bold">' + escapeHtml(i18n.destinationEntityLabel) + '</label>' +
            '          <div id="plugin-clone-entity-container">' +
            '            <div class="d-flex justify-content-center py-3">' +
            '              <div class="spinner-border text-primary" role="status">' +
            '                <span class="visually-hidden">' + escapeHtml(i18n.loadingLabel) + '</span>' +
            '              </div>' +
            '            </div>' +
            '          </div>' +
            '        </div>' +
            '        <div id="plugin-clone-preview" class="mb-3 d-none"></div>' +
            '        <div id="plugin-clone-alert" class="d-none"></div>' +
            '      </div>' +
            '      <div class="modal-footer">' +
            '        <button type="button" class="btn btn-secondary" id="plugin-clone-dismiss" data-bs-dismiss="modal">' + escapeHtml(i18n.cancelLabel) + '</button>' +
            '        <button type="button" class="btn btn-primary" id="plugin-clone-submit">' +
            '          <i class="ti ti-copy me-1"></i> ' + escapeHtml(i18n.cloneLabel) +
            '        </button>' +
            '      </div>' +
            '    </div>' +
            '  </div>' +
            '</div>';

        document.body.insertAdjacentHTML("beforeend", modalHtml);

        var modalEl = document.getElementById("plugin-clone-modal");
        var container = document.getElementById("plugin-clone-entity-container");

        if (typeof bootstrap === "undefined" || !bootstrap.Modal) {
            if (container) {
                container.innerHTML =
                    '<div class="alert alert-danger">' + escapeHtml(i18n.bootstrapMissing) + '</div>';
            }
            return;
        }

        var bsModal = new bootstrap.Modal(modalEl);
        bsModal.show();

        // Load entity dropdown via AJAX
        var dropdownUrl = rootDoc + "/plugins/clone/ajax/get_entity_dropdown.php";

        fetch(dropdownUrl, {
            method: "GET",
            credentials: "same-origin",
            headers: {
                "X-Requested-With": "XMLHttpRequest"
            }
        })
        .then(function (r) { return r.text(); })
        .then(function (html) {
            container.innerHTML = html;
            // Execute inline scripts returned by GLPI dropdown
            var scripts = container.querySelectorAll("script");
            scripts.forEach(function (oldScript) {
                var newScript = document.createElement("script");
                if (oldScript.src) {
                    newScript.src = oldScript.src;
                } else {
                    newScript.textContent = oldScript.textContent;
                }
                document.head.appendChild(newScript);
                document.head.removeChild(newScript);
            });

            // Select2, when present, updates the underlying <select> via
            // jQuery's own event system ($.trigger('change')) -- that does
            // NOT reach a plain addEventListener('change', ...), only
            // jQuery's own .on('change', ...) sees it. Confirmed live: a
            // native listener here silently never fired on Select2
            // selection, so the preview kept showing the previous entity's
            // result. Same "jQuery first, vanilla fallback" split already
            // used by getSelectedEntityIds, applied to the listener itself.
            // Select2 fires the same 'change' event for a multi-select
            // add/remove, so one listener covers both.
            var entitySelect = container.querySelector("select[name='clone_entities_id[]']");
            if (entitySelect) {
                var onEntityChange = function () {
                    fetchAndRenderPreview(rootDoc, ticketId, getSelectedEntityIds(container), i18n);
                };
                if (typeof $ !== "undefined" && $.fn.select2) {
                    $(entitySelect).on("change", onEntityChange);
                } else {
                    entitySelect.addEventListener("change", onEntityChange);
                }
            }

            // Show a preview for whatever is selected by default (normally
            // just the active entity), without waiting for the user to
            // touch the dropdown.
            var initialEntityIds = getSelectedEntityIds(container);
            if (initialEntityIds.length) {
                fetchAndRenderPreview(rootDoc, ticketId, initialEntityIds, i18n);
            }
        })
        .catch(function () {
            container.innerHTML =
                '<div class="alert alert-danger">' + escapeHtml(i18n.entityLoadError) + '</div>';
        });

        // Submit handler
        var submitBtn = document.getElementById("plugin-clone-submit");
        submitBtn.addEventListener("click", function () {
            var entityIds = getSelectedEntityIds(container);

            if (!entityIds.length) {
                showAlert("warning", i18n.selectEntityError);
                return;
            }

            if (entityIds.length > maxTargets) {
                showAlert("warning", i18n.tooManyEntitiesError);
                return;
            }

            var entityNames = getEntityNamesMap(container);

            // Resolved fresh on every click: if this is a retry of the same
            // (ticket, destination set) within the TTL window it reuses the
            // stored key, otherwise (different destinations, or enough time
            // has passed that this reads as a deliberately new attempt) it
            // mints one and persists it as the new stored operation. One
            // batch_uuid covers every destination in this submission -- see
            // PropagationBatchExecutor for why that is still safe per
            // destination.
            var propagationUuid = resolvePropagationUuid(ticketId, entityIds);

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> ' + escapeHtml(i18n.cloningInProgress);

            var formData = new FormData();
            formData.append("ticket_id", ticketId);
            entityIds.forEach(function (id) {
                formData.append("entities_id[]", id);
            });
            formData.append("propagation_uuid", propagationUuid);
            formData.append("_glpi_csrf_token", csrf);

            fetch(ajaxUrl, {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    "X-Requested-With": "XMLHttpRequest",
                    "X-Glpi-Csrf-Token": csrf
                },
                body: formData
            })
            .then(function (response) {
                var contentType = response.headers.get("content-type") || "";
                if (!response.ok && contentType.indexOf("application/json") === -1) {
                    throw new Error("HTTP " + response.status);
                }
                return response.json();
            })
            .then(function (data) {
                if (!data.success) {
                    showAlert("danger", data.message || i18n.unknownErrorLabel);
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="ti ti-copy me-1"></i> ' + escapeHtml(i18n.cloneLabel);
                    return;
                }

                var results = data.results || [];
                var allSucceeded = results.length > 0 && results.every(function (r) { return r.success; });

                renderBulkResults(results, entityNames, i18n);

                if (allSucceeded) {
                    submitBtn.classList.add("d-none");
                    // With the submit button gone, the remaining footer
                    // button is the only way to dismiss the modal -- at that
                    // point it is not cancelling anything, so "Cancel" reads
                    // as wrong. Left as "Cancel" in the partial-failure
                    // branch below, where Propagate is still visible and a
                    // real retry is still on the table.
                    var dismissBtn = document.getElementById("plugin-clone-dismiss");
                    if (dismissBtn) {
                        dismissBtn.textContent = i18n.closeLabel;
                    }
                    // Every destination reached a terminal, successful
                    // state: this exact destination set is done with. The
                    // next "Propagate to entity" click is a genuinely new
                    // attempt and should get a fresh key.
                    clearStoredOperation(ticketId, entityIds);
                } else {
                    // At least one destination failed: keep the stored
                    // batch_uuid so a retry click replays it unchanged.
                    // Already-succeeded destinations then come back as an
                    // idempotent replay instead of a duplicate ticket, and
                    // only the failed ones are actually re-attempted.
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="ti ti-copy me-1"></i> ' + escapeHtml(i18n.cloneLabel);
                }
            })
            .catch(function () {
                showAlert("danger", i18n.communicationErrorLabel);
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="ti ti-copy me-1"></i> ' + escapeHtml(i18n.cloneLabel);
            });
        });

        // Cleanup on close
        modalEl.addEventListener("hidden.bs.modal", function () {
            modalEl.remove();
        });
    }

    function showAlert(type, message) {
        var alertDiv = document.getElementById("plugin-clone-alert");
        if (alertDiv) {
            alertDiv.className = "alert alert-" + type;
            alertDiv.innerHTML = message;
            alertDiv.classList.remove("d-none");
        }
    }

    function escapeHtml(text) {
        var div = document.createElement("div");
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    // Preview: fetches the exact same PropagationPreflightService decision
    // the server will use to execute, once per selected destination, and
    // renders one panel per entity before the user commits. Kept at this
    // outer scope (like escapeHtml/generateUuidV4) rather than nested
    // inside openCloneModal, deliberately -- these take everything they
    // need as parameters, so there's no reason to bury them where they
    // can't be exercised on their own.
    //
    // One entity selected renders exactly like before this was bulk-aware;
    // several just repeat the same per-entity panel under its own heading,
    // one fetch per destination. Not throttled or batched server-side: this
    // is bounded by the same MAX_TARGETS cap as the submission itself, and
    // each request is a cheap, read-only preflight check -- not worth
    // queueing infrastructure for a number that small.
    function fetchAndRenderPreview(rootDoc, ticketId, entityIds, i18n) {
        var previewEl = document.getElementById("plugin-clone-preview");
        if (!previewEl || !entityIds.length) {
            return;
        }

        previewEl.classList.remove("d-none");
        previewEl.innerHTML =
            '<div class="text-muted small"><span class="spinner-border spinner-border-sm me-1"></span>' +
            escapeHtml(i18n.previewLoading) + '</div>';

        var entityNames = getEntityNamesMap(previewEl.closest(".modal-body") || document);

        Promise.all(entityIds.map(function (entityId) {
            var previewUrl = rootDoc + "/plugins/clone/ajax/preview_propagation.php"
                + "?ticket_id=" + encodeURIComponent(ticketId)
                + "&entities_id=" + encodeURIComponent(entityId);

            return fetch(previewUrl, {
                method: "GET",
                credentials: "same-origin",
                headers: { "X-Requested-With": "XMLHttpRequest" }
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                return { entityId: entityId, ok: !!data.success, plan: data.plan || null };
            })
            .catch(function () {
                return { entityId: entityId, ok: false, plan: null };
            });
        })).then(function (perEntity) {
            previewEl.innerHTML = perEntity.map(function (entry) {
                var name = entityNames[entry.entityId] || ("#" + entry.entityId);
                if (!entry.ok) {
                    return '<div class="plugin-clone-preview-panel mb-2">' +
                        '<div class="fw-bold small mb-1">' + escapeHtml(name) + '</div>' +
                        '<div class="text-danger small">' + escapeHtml(i18n.previewError) + '</div>' +
                        '</div>';
                }
                return renderPreviewPlan(name, entry.plan, i18n);
            }).join("");
        });
    }

    function renderPreviewPlan(entityName, plan, i18n) {
        var fields = [
            {key: "category", label: i18n.previewFieldCategory},
            {key: "location", label: i18n.previewFieldLocation},
            {key: "requester", label: i18n.previewFieldRequester},
            {key: "assignee", label: i18n.previewFieldAssignee},
            {key: "observer", label: i18n.previewFieldObserver},
            {key: "group", label: i18n.previewFieldGroup}
        ];

        var rows = fields.map(function (field) {
            var decision = plan[field.key];
            if (!decision) {
                return "";
            }
            var kept = decision.action === "preserve";
            var badgeClass = kept ? "bg-success" : "bg-secondary";
            var badgeText = kept ? i18n.previewKeptLabel : i18n.previewClearedLabel;
            return '<div class="plugin-clone-preview-row d-flex justify-content-between align-items-start py-1">' +
                '<div>' +
                '<span class="fw-bold">' + escapeHtml(field.label) + '</span>' +
                '<div class="text-muted small">' + escapeHtml(decision.reason) + '</div>' +
                '</div>' +
                '<span class="badge ' + badgeClass + ' ms-2">' + escapeHtml(badgeText) + '</span>' +
                '</div>';
        }).join("");

        return '<div class="plugin-clone-preview-panel mb-2">' +
            '<div class="fw-bold small mb-1">' + escapeHtml(entityName) + '</div>' +
            rows +
            '</div>';
    }

    // Renders one row per destination after the propagation request
    // returns, success or failure, instead of a single alert for the whole
    // batch -- with several destinations, "3 of 5 succeeded" as one message
    // hides exactly the information (which two, and why) needed to decide
    // what to retry.
    function renderBulkResults(results, entityNames, i18n) {
        var alertDiv = document.getElementById("plugin-clone-alert");
        if (!alertDiv) {
            return;
        }

        var succeeded = results.filter(function (r) { return r.success; }).length;
        var total = results.length;
        var summaryClass = total === 0 ? "warning" : (succeeded === total ? "success" : (succeeded === 0 ? "danger" : "warning"));

        var rows = results.map(function (r) {
            var name = entityNames[r.entities_id] || ("#" + r.entities_id);
            var icon = r.success
                ? '<i class="ti ti-circle-check text-success me-1"></i>'
                : '<i class="ti ti-circle-x text-danger me-1"></i>';
            var link = (r.success && r.ticket_url)
                ? ' <a href="' + escapeHtml(r.ticket_url) + '" class="alert-link">' + escapeHtml(i18n.openNewTicketLabel) + '</a>'
                : '';
            return '<div class="plugin-clone-result-row small py-1">' +
                icon + '<strong>' + escapeHtml(name) + ':</strong> ' + escapeHtml(r.message || "") + link +
                '</div>';
        }).join("");

        alertDiv.className = "alert alert-" + summaryClass;
        alertDiv.innerHTML =
            '<div class="fw-bold mb-1">' + succeeded + ' / ' + total + ' ' + escapeHtml(i18n.resultsSummaryLabel) + '</div>' +
            rows;
        alertDiv.classList.remove("d-none");
    }

    function getSelectedEntityIds(container) {
        // Prefer jQuery/Select2 API when available
        if (typeof $ !== "undefined" && $.fn.select2) {
            var $sel = $(container).find("select[name='clone_entities_id[]']");
            if ($sel.length) {
                return ($sel.val() || []).map(String);
            }
        }
        // Fallback to vanilla DOM (native multi-select)
        var entitySelect = container.querySelector("select[name='clone_entities_id[]']");
        if (!entitySelect) {
            return [];
        }
        return Array.prototype.slice.call(entitySelect.selectedOptions).map(function (opt) {
            return opt.value;
        });
    }

    function getEntityNamesMap(container) {
        var map = {};
        if (!container) {
            return map;
        }
        var options = container.querySelectorAll("select[name='clone_entities_id[]'] option");
        options.forEach(function (opt) {
            map[opt.value] = opt.textContent;
        });
        return map;
    }

    function generateUuidV4() {
        if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function") {
            return crypto.randomUUID();
        }
        // Fallback for browsers without crypto.randomUUID (non-secure
        // contexts, older browsers). Not cryptographically strong, but this
        // value is only ever used as a request-deduplication key, never a
        // security token.
        return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, function (c) {
            var r = (Math.random() * 16) | 0;
            var v = c === "x" ? r : (r & 0x3) | 0x8;
            return v.toString(16);
        });
    }
})();
