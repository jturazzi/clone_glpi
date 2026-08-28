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

    // Keyed by (ticket, target entity), not ticket alone: a single
    // ticket->entity slot would let propagating the same ticket to a
    // *second* entity silently overwrite the first entity's still-relevant
    // operation record. Confirmed by test: with a ticket-only key,
    // B -> C -> back to B produced a fresh UUID for B against a slot the
    // server has no record of -- if the original B attempt had already
    // succeeded, that reissues a second ticket in B, not a caught
    // duplicate. That failure mode is worse than the accepted "2 hours
    // later" gap because it can happen within seconds of ordinary use
    // (propagate to two different entities, then revisit the first).
    function operationStorageKey(ticketId, targetEntityId) {
        return PROPAGATION_OP_STORAGE_PREFIX + ticketId + "_" + targetEntityId;
    }

    function readStoredOperation(ticketId, targetEntityId) {
        var storageKey = operationStorageKey(ticketId, targetEntityId);
        try {
            var raw = window.sessionStorage.getItem(storageKey);
            var parsed = raw ? JSON.parse(raw) : null;
            return isValidOperation(parsed) ? parsed : null;
        } catch (e) {
            var fallback = inMemoryPropagationOps[storageKey] || null;
            return isValidOperation(fallback) ? fallback : null;
        }
    }

    function writeStoredOperation(ticketId, targetEntityId, operation) {
        operation.version = PROPAGATION_OP_VERSION;
        var storageKey = operationStorageKey(ticketId, targetEntityId);
        try {
            window.sessionStorage.setItem(storageKey, JSON.stringify(operation));
        } catch (e) {
            inMemoryPropagationOps[storageKey] = operation;
        }
    }

    // Discard the operation only once the propagation has definitively
    // succeeded (per the locked design: happens after success, never merely
    // because the Submit button becomes clickable again).
    function clearStoredOperation(ticketId, targetEntityId) {
        var storageKey = operationStorageKey(ticketId, targetEntityId);
        try {
            window.sessionStorage.removeItem(storageKey);
        } catch (e) {
            // ignore: nothing to clean up if storage was never available
        }
        delete inMemoryPropagationOps[storageKey];
    }

    /**
     * Idempotency key for this (ticket, destination entity) submission.
     * Reused only if a record already exists for this exact pair and is
     * still within the TTL window; a different destination entity is a
     * different storage slot entirely, so it always gets its own fresh key
     * with no risk of clobbering another entity's still-relevant record.
     * Resolved at first submit, not at modal open -- opening the modal and
     * not submitting reserves nothing.
     */
    function resolvePropagationUuid(ticketId, targetEntityId) {
        var existing = readStoredOperation(ticketId, targetEntityId);
        var now = Date.now();

        if (existing && (now - existing.createdAt) < PROPAGATION_OP_TTL_MS) {
            return existing.batchUuid;
        }

        var fresh = generateUuidV4();
        writeStoredOperation(ticketId, targetEntityId, {
            batchUuid: fresh,
            targetEntityId: targetEntityId,
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
            selectEntityError: btn.getAttribute("data-i18n-select-entity-error") || "Please select a destination entity.",
            cloningInProgress: btn.getAttribute("data-i18n-cloning-in-progress") || "Propagating...",
            openNewTicketLabel: btn.getAttribute("data-i18n-open-new-ticket-label") || "Open the new ticket",
            unknownErrorLabel: btn.getAttribute("data-i18n-unknown-error-label") || "Unknown error.",
            communicationErrorLabel: btn.getAttribute("data-i18n-communication-error-label") || "Communication error with server.",
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
            '  <div class="modal-dialog modal-dialog-centered">' +
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
            '        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">' + escapeHtml(i18n.cancelLabel) + '</button>' +
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
            // used by getSelectedEntityId, applied to the listener itself.
            var entitySelect = container.querySelector("select[name='clone_entities_id']");
            if (entitySelect) {
                var onEntityChange = function () {
                    fetchAndRenderPreview(rootDoc, ticketId, getSelectedEntityId(container), i18n);
                };
                if (typeof $ !== "undefined" && $.fn.select2) {
                    $(entitySelect).on("change", onEntityChange);
                } else {
                    entitySelect.addEventListener("change", onEntityChange);
                }
            }

            // Show a preview for whatever entity is selected by default,
            // without waiting for the user to touch the dropdown.
            var initialEntityId = getSelectedEntityId(container);
            if (initialEntityId) {
                fetchAndRenderPreview(rootDoc, ticketId, initialEntityId, i18n);
            }
        })
        .catch(function () {
            container.innerHTML =
                '<div class="alert alert-danger">' + escapeHtml(i18n.entityLoadError) + '</div>';
        });

        // Submit handler
        var submitBtn = document.getElementById("plugin-clone-submit");
        submitBtn.addEventListener("click", function () {
            var entityId = getSelectedEntityId(container);

            if (entityId === null || entityId === "" || entityId === undefined) {
                showAlert("warning", i18n.selectEntityError);
                return;
            }

            // Resolved fresh on every click: if this is a retry of the same
            // (ticket, destination) within the TTL window it reuses the
            // stored key, otherwise (different destination, or enough time
            // has passed that this reads as a deliberately new attempt) it
            // mints one and persists it as the new stored operation.
            var propagationUuid = resolvePropagationUuid(ticketId, entityId);

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> ' + escapeHtml(i18n.cloningInProgress);

            var formData = new FormData();
            formData.append("ticket_id", ticketId);
            formData.append("entities_id", entityId);
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
                if (data.success) {
                    showAlert("success",
                        data.message +
                        ' <a href="' + escapeHtml(data.ticket_url) + '" class="alert-link">' + escapeHtml(i18n.openNewTicketLabel) + '</a>'
                    );
                    submitBtn.classList.add("d-none");
                    // Definitive success (including an idempotent replay of an
                    // already-completed propagation): this ticket ID is done
                    // with. The next "Propagate to entity" click on it is a
                    // genuinely new attempt and should get a fresh key.
                    clearStoredOperation(ticketId, entityId);
                } else {
                    showAlert("danger", data.message || i18n.unknownErrorLabel);
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
    // the server will use to execute, and renders it before the user
    // commits. Kept at this outer scope (like escapeHtml/generateUuidV4)
    // rather than nested inside openCloneModal, deliberately -- these take
    // everything they need as parameters, so there's no reason to bury
    // them where they can't be exercised on their own.
    function fetchAndRenderPreview(rootDoc, ticketId, entityId, i18n) {
        var previewEl = document.getElementById("plugin-clone-preview");
        if (!previewEl || !entityId) {
            return;
        }

        previewEl.classList.remove("d-none");
        previewEl.innerHTML =
            '<div class="text-muted small"><span class="spinner-border spinner-border-sm me-1"></span>' +
            escapeHtml(i18n.previewLoading) + '</div>';

        var previewUrl = rootDoc + "/plugins/clone/ajax/preview_propagation.php"
            + "?ticket_id=" + encodeURIComponent(ticketId)
            + "&entities_id=" + encodeURIComponent(entityId);

        fetch(previewUrl, {
            method: "GET",
            credentials: "same-origin",
            headers: { "X-Requested-With": "XMLHttpRequest" }
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.success) {
                previewEl.innerHTML = '<div class="text-danger small">' + escapeHtml(i18n.previewError) + '</div>';
                return;
            }
            previewEl.innerHTML = renderPreviewPlan(data.plan, i18n);
        })
        .catch(function () {
            previewEl.innerHTML = '<div class="text-danger small">' + escapeHtml(i18n.previewError) + '</div>';
        });
    }

    function renderPreviewPlan(plan, i18n) {
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

        return '<div class="plugin-clone-preview-panel">' +
            '<div class="fw-bold small mb-1">' + escapeHtml(i18n.previewHeading) + '</div>' +
            rows +
            '</div>';
    }

    function getSelectedEntityId(container) {
        // Prefer jQuery/Select2 API when available
        if (typeof $ !== "undefined" && $.fn.select2) {
            var $sel = $(container).find("select[name='clone_entities_id']");
            if ($sel.length) {
                return $sel.val();
            }
        }
        // Fallback to vanilla DOM
        var entitySelect = container.querySelector("select[name='clone_entities_id']");
        if (!entitySelect) {
            entitySelect = container.querySelector("input[name='clone_entities_id']");
        }
        return entitySelect ? entitySelect.value : null;
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
