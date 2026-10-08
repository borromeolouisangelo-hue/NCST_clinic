/* ========================================================================
   School Clinic IMS - Front-end helpers
   ======================================================================== */
(function () {
    'use strict';

    // ---------------------------------------------------------------
    // Central SweetAlert helpers (Feature 5: SweetAlert System)
    // ---------------------------------------------------------------
    window.ClinicAlert = {
        /** Success toast (auto-dismiss) */
        success: function (title, text) {
            return Swal.fire({
                icon: 'success',
                title: title,
                text: text,
                timer: 3000,
                timerProgressBar: true,
                showConfirmButton: false,
                toast: true,
                position: 'top-end'
            });
        },

        /** Error toast (auto-dismiss) */
        error: function (title, text) {
            return Swal.fire({
                icon: 'error',
                title: title,
                text: text,
                timer: 4000,
                timerProgressBar: true,
                showConfirmButton: false,
                toast: true,
                position: 'top-end'
            });
        },

        /** Warning toast (auto-dismiss) */
        warning: function (title, text) {
            return Swal.fire({
                icon: 'warning',
                title: title,
                text: text,
                timer: 4000,
                timerProgressBar: true,
                showConfirmButton: false,
                toast: true,
                position: 'top-end'
            });
        },

        /** Info toast (auto-dismiss) */
        info: function (title, text) {
            return Swal.fire({
                icon: 'info',
                title: title,
                text: text,
                timer: 4000,
                timerProgressBar: true,
                showConfirmButton: false,
                toast: true,
                position: 'top-end'
            });
        },

        /** Confirmation dialog for destructive/important actions */
        confirm: function (options) {
            return Swal.fire({
                title: options.title || 'Are you sure?',
                text: options.text || "This action cannot be undone.",
                icon: options.icon || 'warning',
                showCancelButton: true,
                confirmButtonColor: options.confirmColor || '#3085d6',
                cancelButtonColor: options.cancelColor || '#d33',
                confirmButtonText: options.confirmText || 'Yes, do it!',
                cancelButtonText: options.cancelText || 'Cancel',
                reverseButtons: true,
                focusCancel: true,
            }).then(function (result) {
                return result.isConfirmed;
            });
        },

        /** Save/update confirmation (replaces data-swal-save) */
        confirmSave: function (itemName) {
            return Swal.fire({
                title: 'Save changes?',
                text: 'Do you want to save the changes to ' + (itemName || 'this record') + '?',
                icon: 'question',
                showDenyButton: true,
                showCancelButton: true,
                confirmButtonText: 'Save',
                denyButtonText: "Don't save",
                cancelButtonText: 'Cancel',
                focusCancel: true,
            });
        },

        /** Delete confirmation (replaces data-swal-delete) */
        confirmDelete: function (itemName) {
            return Swal.fire({
                title: 'Delete ' + (itemName || 'this record') + '?',
                text: "You won't be able to revert this!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel',
                reverseButtons: true,
                focusCancel: true,
            });
        },

        /** Add/create confirmation (replaces data-swal-add) */
        confirmAdd: function (itemName) {
            return Swal.fire({
                title: 'Add ' + (itemName || 'this record') + '?',
                text: 'Confirm adding this new record.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, add it!',
                cancelButtonText: 'Cancel',
                reverseButtons: true,
                focusCancel: true,
            });
        },

        /** Validation error alert (inline form errors) */
        validationError: function (errors) {
            var html = '<ul class="text-start mb-0">';
            errors.forEach(function (e) { html += '<li>' + e + '</li>'; });
            html += '</ul>';
            return Swal.fire({
                icon: 'error',
                title: 'Validation Error',
                html: html,
                confirmButtonText: 'OK',
            });
        },
    };

    document.addEventListener('DOMContentLoaded', function () {
        // Reset all modal forms when a modal is closed
        document.querySelectorAll('.modal').forEach(function (modal) {
            modal.addEventListener('hidden.bs.modal', function () {
                var form = modal.querySelector('form');
                if (form) {
                    form.reset();
                    // Also clear any validation error messages inside the modal
                    form.querySelectorAll('.alert-danger').forEach(function (alert) {
                        alert.remove();
                    });
                }
            });
        });

        var sidebar = document.getElementById('appSidebar');
        // The old topbar hamburger (#sidebarToggle) has been removed - the button
        // in the sidebar header is the ONE navigation control, for every role and
        // every breakpoint. It is still looked up so stale markup anywhere in the
        // app degrades to a no-op instead of throwing.
        var toggle = document.getElementById('sidebarToggle');
        var brandControl = document.getElementById('sidebarBrandControl');
        var backdrop = document.getElementById('sidebarBackdrop');
        var wrapper = sidebar ? sidebar.closest('.app-wrapper') : null;
        var mobileQuery = window.matchMedia('(max-width: 991.98px)');
        // Remembered across pages: 'true' while the rail is collapsed.
        // One implementation serves every role.
        var sidebarStateKey = 'clinicSidebarCollapsed';

        function isSidebarCollapsed() {
            return !!(wrapper && wrapper.classList.contains('sidebar-collapsed'));
        }

        // Is the navigation reachable right now? Desktop = rail not collapsed,
        // mobile = drawer open. Everything below derives from this, so aria state
        // and the body scroll-lock always match the real DOM.
        function isNavOpen() {
            if (mobileQuery.matches) return !!(sidebar && sidebar.classList.contains('show'));
            return !isSidebarCollapsed();
        }

        function setToggleState() {
            var open = isNavOpen();
            var mobile = mobileQuery.matches;
            var label = open
                ? (mobile ? 'Close navigation' : 'Collapse navigation')
                : (mobile ? 'Open navigation' : 'Expand navigation');
            if (brandControl) {
                brandControl.setAttribute('aria-expanded', open ? 'true' : 'false');
                brandControl.setAttribute('aria-label', label);
                brandControl.setAttribute('title', label);
            }
            if (toggle) {
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                toggle.setAttribute('aria-label', label);
                toggle.setAttribute('title', label);
            }
            document.body.classList.toggle('sidebar-menu-open', mobile && open);
        }

        function setBrandControlState() {
            setToggleState();
        }

        function openSidebar() {
            if (sidebar) sidebar.classList.add('show');
            if (backdrop) backdrop.classList.add('show');
            setToggleState(true);
        }
        function closeSidebar() {
            if (sidebar) sidebar.classList.remove('show');
            if (backdrop) backdrop.classList.remove('show');
            setToggleState(false);
        }

        // Single source of truth for the persistent desktop rail state: the
        // sidebar header control and rail hover both go through here, so they
        // can never disagree.
        function setSidebarCollapsed(collapsed) {
            if (!wrapper) return;
            wrapper.classList.toggle('sidebar-collapsed', collapsed);
            if (sidebar) sidebar.classList.remove('sidebar-hover-expanded');
            try {
                localStorage.setItem(sidebarStateKey, collapsed ? 'true' : 'false');
            } catch (e) {
                // Storage unavailable (private mode) — state stays in-memory.
            }
            setToggleState(!collapsed);
            setBrandControlState(collapsed);
        }
        if (toggle) toggle.addEventListener('click', function () {
            if (mobileQuery.matches) {
                // Below 992px the sidebar is an off-canvas drawer.
                if (sidebar && sidebar.classList.contains('show')) closeSidebar();
                else openSidebar();
                return;
            }
            setSidebarCollapsed(!isSidebarCollapsed());
        });
        if (backdrop) backdrop.addEventListener('click', closeSidebar);

        // Hovering the collapsed rail pops it out to full width. It STAYS
        // expanded after the pointer leaves; only "<" (the sidebar header
        // control) collapses it again.
        if (sidebar) {
            sidebar.addEventListener('mouseenter', function () {
                if (!mobileQuery.matches && isSidebarCollapsed()) setSidebarCollapsed(false);
            });
        }

        // The single control drives both modes: below 992px it opens/closes the
        // off-canvas drawer, at 992px and up it collapses/expands the rail.
        if (brandControl) brandControl.addEventListener('click', function () {
            if (mobileQuery.matches) {
                if (sidebar && sidebar.classList.contains('show')) closeSidebar();
                else openSidebar();
                return;
            }
            setSidebarCollapsed(!isSidebarCollapsed());
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && mobileQuery.matches && sidebar && sidebar.classList.contains('show')) {
                closeSidebar();
                if (brandControl) brandControl.focus();
            }
        });
        // Restore the remembered desktop state ('true' = rail collapsed).
        setToggleState(!(wrapper && isSidebarCollapsed()));
        setBrandControlState(isSidebarCollapsed());
        if (!mobileQuery.matches && wrapper && localStorage.getItem(sidebarStateKey) === 'true') {
            wrapper.classList.add('sidebar-collapsed');
            setToggleState(false);
            setBrandControlState(true);
        }
        mobileQuery.addEventListener('change', function (event) {
            if (sidebar) sidebar.classList.remove('sidebar-hover-expanded');
            if (event.matches) {
                if (wrapper) wrapper.classList.remove('sidebar-collapsed');
                closeSidebar();
            } else {
                closeSidebar();
                if (wrapper && localStorage.getItem(sidebarStateKey) === 'true') {
                    wrapper.classList.add('sidebar-collapsed');
                }
                setToggleState(!isSidebarCollapsed());
                setBrandControlState(isSidebarCollapsed());
            }
        });

        document.querySelectorAll('.alert-auto-dismiss').forEach(function (el) {
            setTimeout(function () {
                el.style.transition = 'opacity .4s';
                el.style.opacity = '0';
                setTimeout(function () { el.remove(); }, 400);
            }, 5000);
        });

        document.querySelectorAll('form[data-confirm]').forEach(function (form) {
            // Skip if a SweetAlert handler already handles this form (data-swal-* takes precedence)
            if (form.hasAttribute('data-swal-save') || form.hasAttribute('data-swal-add') || form.hasAttribute('data-swal-delete')) {
                return;
            }
            form.addEventListener('submit', function (ev) {
                if (!window.confirm(form.getAttribute('data-confirm'))) {
                    ev.preventDefault();
                }
            });
        });

        document.querySelectorAll('form[data-auto-submit]').forEach(function (form) {
            form.querySelectorAll('select, input[type="date"]').forEach(function (field) {
                field.addEventListener('change', function () { form.submit(); });
            });
        });

        document.querySelectorAll('a[data-confirm]').forEach(function (el) {
            // Skip if a SweetAlert handler already handles this link
            if (el.hasAttribute('data-swal-delete') || el.hasAttribute('data-swal-add')) {
                return;
            }
            el.addEventListener('click', function (ev) {
                var msg = el.getAttribute('data-confirm') || 'Are you sure?';
                if (!window.confirm(msg)) {
                    ev.preventDefault();
                }
            });
        });

        // ---- SweetAlert2: Delete confirmations (data-swal-delete) ----
        // On confirm, navigate/submit directly; success toast comes from server flash
        // via the shared flash-toast carrier (Feature 4/5). No premature toast here.
        document.querySelectorAll('a[data-swal-delete], button[data-swal-delete]').forEach(function (el) {
            el.addEventListener('click', function (ev) {
                ev.preventDefault();
                var itemName = el.getAttribute('data-swal-delete') || 'record';
                // Resolved value is the RAW SweetAlert result - it is truthy for
                // Cancel/dismiss as well, so ask isConfirmed explicitly (the same
                // check the data-swal-save handler below already performs).
                ClinicAlert.confirmDelete(itemName).then(function (result) {
                    if (result.isConfirmed) {
                        if (el.tagName === 'A') {
                            window.location.href = el.href;
                        } else {
                            el.closest('form').submit();
                        }
                    }
                });
            });
        });

        // ---- SweetAlert2: Save/Update confirmations (data-swal-save) ----
        // On confirm, submit directly; success toast comes from server flash
        // via the shared flash-toast carrier (Feature 4/5). No premature toast here.
        // Respect formnovalidate on the clicked submitter.
        document.querySelectorAll('form[data-swal-save], button[data-swal-save]').forEach(function (el) {
            el.addEventListener('submit', function (ev) {
                var form = el.tagName === 'FORM' ? el : el.closest('form');
                var submitter = ev.submitter && ev.submitter.type === 'submit' ? ev.submitter : null;

                // If the clicked submitter has formnovalidate, skip validation
                if (submitter && submitter.hasAttribute('formnovalidate')) {
                    ev.preventDefault();
                    form.submit();
                    return;
                }

                var itemName = el.getAttribute('data-swal-save') || 'changes';
                ClinicAlert.confirmSave(itemName).then(function (result) {
                    if (result.isConfirmed) {
                        form.submit();
                    } else if (result.isDenied) {
                        ClinicAlert.info('Not saved', 'Changes were not saved.');
                    }
                });
            });
        });

        // ---- SweetAlert2: Add confirmations (data-swal-add) ----
        // On confirm, submit/navigate directly; success toast comes from server flash
        // via the shared flash-toast carrier (Feature 4/5). No premature toast here.
        document.querySelectorAll('button[data-swal-add], a[data-swal-add]').forEach(function (el) {
            el.addEventListener('click', function (ev) {
                ev.preventDefault();
                var itemName = el.getAttribute('data-swal-add') || 'record';
                var form = el.closest('form');
                // Resolved value is the RAW SweetAlert result - it is truthy for
                // Cancel/dismiss as well, so ask isConfirmed explicitly (the same
                // check the data-swal-save handler above already performs).
                ClinicAlert.confirmAdd(itemName).then(function (result) {
                    if (result.isConfirmed) {
                        if (form) {
                            form.submit();
                        } else if (el.tagName === 'A') {
                            window.location.href = el.href;
                        }
                    }
                });
            });
        });

        document.querySelectorAll('[data-delete-modal]').forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                var target = document.getElementById('confirmDeleteBatch');
                if (target) target.href = trigger.getAttribute('data-delete-modal');
            });
        });

        document.querySelectorAll('form').forEach(function (form) {
            form.addEventListener('submit', function (ev) {
                // Decorate the button that was ACTUALLY clicked: a form may
                // carry several decisions (the sick-leave details modal's
                // Accept/Decline), and spinning the first submit button would
                // name the wrong one while the real submitter stayed live.
                // Keyboard and programmatic submits report no submitter, so
                // they keep the old first-button behaviour.
                var origin = ev && ev.submitter;
                var submit = (origin && origin.type === 'submit')
                    ? origin
                    : form.querySelector('button[type="submit"], button:not([type])');
                if (!submit || form.dataset.confirmed === 'false') return;
                // A disabled control is not a successful control, and the form
                // data set is built AFTER this event - so disabling the button
                // that carries the click would silently drop its name/value
                // (inventory Full/Half/Empty, dispense allergy decision, the
                // sick-leave review status, ...). Copy it into a hidden field
                // first, then show the spinner.
                if (origin && origin === submit && origin.name && !form.querySelector('input[data-submit-value]')) {
                    var keep = document.createElement('input');
                    keep.type = 'hidden';
                    keep.name = origin.name;
                    keep.value = origin.value;
                    keep.setAttribute('data-submit-value', '');
                    form.appendChild(keep);
                }
                submit.disabled = true;
                submit.dataset.originalLabel = submit.innerHTML;
                submit.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Saving...';
            });
        });

        // Feature 4: the review queue's success is flashed through a partial
        // that renders nothing on purpose, so Accept/Decline used to finish
        // silently. Turn the flash the SERVER set into a toast - the carrier
        // only exists on the request that followed a completed POST, so a
        // success can never be shown before it happened, nor twice.
        var flashNotice = document.getElementById('flashNotice');
        if (flashNotice && typeof Swal !== 'undefined') {
            var flashText = flashNotice.getAttribute('data-flash-text');
            if (flashText) {
                Swal.fire({
                    icon: flashNotice.getAttribute('data-flash-icon') || 'success',
                    title: flashText,
                    showConfirmButton: false,
                    timer: 2600,
                    timerProgressBar: true,
                });
            }
        }

        function debounce(fn, wait) {
            var timeout = null;
            return function () {
                var context = this;
                var args = arguments;
                clearTimeout(timeout);
                timeout = setTimeout(function () {
                    fn.apply(context, args);
                }, wait || 250);
            };
        }

        document.querySelectorAll('[data-live-filter]').forEach(function (input) {
            var selector = input.getAttribute('data-live-filter');
            var rows = Array.prototype.slice.call(document.querySelectorAll(selector));
            var countTarget = document.getElementById(input.getAttribute('data-live-filter-count'));

            var applyFilter = debounce(function () {
                var query = (input.value || '').trim().toLowerCase();
                var visibleCount = 0;

                rows.forEach(function (row) {
                    var text = (row.textContent || '').toLowerCase();
                    var matches = query === '' || text.indexOf(query) !== -1;
                    row.classList.toggle('d-none', !matches);
                    if (matches) visibleCount += 1;
                });

                if (countTarget) {
                    countTarget.textContent = query ? visibleCount + ' match' + (visibleCount === 1 ? '' : 'es') : 'All records';
                }
            }, 200);

            input.addEventListener('input', applyFilter);
            applyFilter();
        });

        document.querySelectorAll('[data-search-trigger]').forEach(function (button) {
            button.addEventListener('click', function () {
                var input = document.querySelector(button.getAttribute('data-search-trigger'));
                if (input) {
                    input.focus();
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                }
            });
        });

        document.querySelectorAll('[data-patient-combobox]').forEach(function (input) {
            var hidden = document.querySelector(input.getAttribute('data-patient-combobox'));
            var list = document.getElementById(input.getAttribute('list'));
            if (!hidden || !list) return;
            var options = Array.from(list.options);
            var syncPatient = function () {
                var value = input.value.trim().toLowerCase();
                var match = options.find(function (option) {
                    return option.value.trim().toLowerCase() === value;
                });
                if (!match && value) {
                    var matches = options.filter(function (option) {
                        return option.value.toLowerCase().indexOf(value) !== -1;
                    });
                    if (matches.length === 1) match = matches[0];
                }
                hidden.value = match ? match.dataset.userId : '';
            };
            input.addEventListener('input', function () {
                syncPatient();
            });
            input.addEventListener('change', syncPatient);
            input.addEventListener('blur', syncPatient);
            var form = input.closest('form');
            if (form) {
                form.addEventListener('submit', function (event) {
                    syncPatient();
                    if (!hidden.value) {
                        event.preventDefault();
                        input.setCustomValidity('Select a patient from the suggestions.');
                        input.reportValidity();
                    } else {
                        input.setCustomValidity('');
                    }
                });
                input.addEventListener('input', function () { input.setCustomValidity(''); });
            }
        });

        document.querySelectorAll('[data-export-table]').forEach(function (button) {
            button.addEventListener('click', function () {
                var table = document.querySelector(button.getAttribute('data-export-table'));
                if (!table) return;
                var rows = Array.from(table.querySelectorAll('tr')).map(function (row) {
                    return Array.from(row.querySelectorAll('th, td')).map(function (cell) {
                        return '"' + cell.textContent.trim().replace(/"/g, '""') + '"';
                    }).join(',');
                });
                var blob = new Blob([rows.join('\n')], { type: 'text/csv;charset=utf-8;' });
                var link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = (button.getAttribute('data-export-name') || 'clinic-report') + '.csv';
                link.click();
                URL.revokeObjectURL(link.href);
            });
        });

        document.querySelectorAll('[data-profile-tab-button]').forEach(function (button) {
            button.addEventListener('click', function () {
                var target = button.getAttribute('data-profile-tab-button');
                var profileRow = document.querySelector('.patient-profile-row');
                if (profileRow) profileRow.classList.toggle('activity-view', target === 'activity');
                document.querySelectorAll('[data-profile-tab-button]').forEach(function (item) {
                    item.classList.toggle('active', item === button);
                });
                document.querySelectorAll('[data-profile-tab-panel]').forEach(function (panel) {
                    panel.classList.toggle('d-none', panel.getAttribute('data-profile-tab-panel') !== target);
                });
            });
        });

        document.querySelectorAll('form[data-autosave]').forEach(function (form) {
            var key = 'school-clinic-draft:' + form.getAttribute('data-autosave');
            var fields = form.querySelectorAll('input[name], select[name], textarea[name]');
            try {
                var saved = JSON.parse(localStorage.getItem(key) || '{}');
                fields.forEach(function (field) {
                    if (saved[field.name] !== undefined && saved[field.name] !== field.value) field.value = saved[field.name];
                    field.addEventListener('input', function () {
                        var draft = {};
                        fields.forEach(function (item) { draft[item.name] = item.value; });
                        localStorage.setItem(key, JSON.stringify(draft));
                    });
                });
                form.addEventListener('submit', function () { localStorage.removeItem(key); });
            } catch (err) {
                // Draft saving is optional and must never block form use.
            }
        });

        var timeoutModal = document.getElementById('sessionTimeoutModal');
        var continueSession = document.getElementById('continueSession');
        var countdown = document.getElementById('sessionCountdown');
        if (timeoutModal && window.bootstrap && continueSession) {
            var timeout = 55 * 60 * 1000;
            var warning = 5 * 60;
            var timer;
            var modal = new bootstrap.Modal(timeoutModal);
            var startTimeout = function () {
                clearTimeout(timer);
                timer = setTimeout(function () {
                    var remaining = warning;
                    modal.show();
                    countdown.textContent = '5:00';
                    var interval = setInterval(function () {
                        remaining--;
                        if (remaining <= 0) {
                            clearInterval(interval);
                            window.location.href = document.body.dataset.logoutUrl || 'auth/logout.php';
                            return;
                        }
                        countdown.textContent = Math.floor(remaining / 60) + ':' + String(remaining % 60).padStart(2, '0');
                    }, 1000);
                    continueSession.onclick = function () {
                        clearInterval(interval);
                        modal.hide();
                        startTimeout();
                    };
                }, timeout);
            };
            startTimeout();
        }

        var bd = document.getElementById('birthdate');
        var age = document.getElementById('ageField');
        if (bd && age) {
            var calc = function () {
                if (!bd.value) { age.value = ''; return; }
                var d = new Date(bd.value), now = new Date();
                var a = now.getFullYear() - d.getFullYear();
                var m = now.getMonth() - d.getMonth();
                if (m < 0 || (m === 0 && now.getDate() < d.getDate())) a--;
                age.value = (a >= 0) ? a : '';
            };
            bd.addEventListener('change', calc);
            calc();
        }

        // ---- Notification Bell (SSE-based) --------------------------------
        var notifBellBtn = document.getElementById('notifBellBtn');
        var notifBadge = document.getElementById('notifBadge');
        var notifList = document.getElementById('notifList');
        var notifEventSource = null;
        // Feature 2 (dedup): ids already in the dropdown. The list is filled
        // only by the SSE stream, so a reconnect - or the stream's 5-second
        // catch-up window - can otherwise prepend a row the bell already has.
        var notifSeenIds = {};

        function renderNotifIcon(severity) {
            var icons = {
                success: 'bi-check-circle-fill',
                warning: 'bi-exclamation-triangle-fill',
                danger: 'bi-x-octagon-fill',
                info: 'bi-bell-fill'
            };
            return icons[severity] || 'bi-bell-fill';
        }

        function renderNotifItem(n) {
            var unreadClass = n.is_read ? '' : 'fw-bold';
            var borderClass = n.is_read ? '' : 'border-start border-4 border-primary';
            var actionLink = n.action_url
                ? '<a href="' + n.action_url + '" class="btn btn-sm btn-outline-primary py-0 px-2 ms-2">View</a>'
                : '';
            var markRead = n.is_read
                ? ''
                : '<a href="#" class="text-muted small mark-read" data-id="' + n.id + '">Mark read</a>';
            return '<li class="dropdown-item-text px-3 py-2 ' + borderClass + '">' +
                '<div class="d-flex align-items-start gap-2">' +
                    '<i class="bi ' + renderNotifIcon(n.severity) + ' text-' + (n.severity === 'warning' ? 'warning' : n.severity) + '"></i>' +
                    '<div class="flex-grow-1">' +
                        '<div class="small ' + unreadClass + '">' + n.title + '</div>' +
                        '<div class="small text-muted">' + n.message.substring(0, 80) + (n.message.length > 80 ? '...' : '') + '</div>' +
                        '<div class="d-flex align-items-center gap-1 mt-1">' + actionLink + markRead + '</div>' +
                    '</div>' +
                    '<small class="text-muted flex-shrink-0">' + timeAgo(n.created_at) + '</small>' +
                '</div>' +
            '</li><li><hr class="dropdown-divider my-1"></li>';
        }

        function timeAgo(dateStr) {
            if (!dateStr) return '';
            var then = new Date(dateStr.replace(' ', 'T'));
            if (isNaN(then.getTime())) return '';
            var now = new Date();
            var diff = Math.floor((now - then) / 1000);
            if (diff < 60) return diff + 's ago';
            if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
            if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
            return Math.floor(diff / 86400) + 'd ago';
        }

        function updateNotifBadge(count) {
            if (!notifBadge) return;
            if (count > 0) {
                notifBadge.textContent = count > 99 ? '99+' : count;
                notifBadge.classList.remove('d-none');
            } else {
                notifBadge.classList.add('d-none');
            }
        }

        // Feature 2 (scoping): the badge is the caller's own unread count,
        // asked for rather than guessed. Adding one to whatever the badge
        // happened to show double-counted a row the init event had already
        // reported (and drifted further with every reconnect), so a bell
        // could read 2 for a user who had exactly 1 unread row.
        var notifBadgeRefreshPending = false;
        function refreshNotifBadge() {
            if (!notifBadge || notifBadgeRefreshPending) return;
            notifBadgeRefreshPending = true;
            fetch(appBase + '/api/notifications.php', { credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    notifBadgeRefreshPending = false;
                    if (data && typeof data.unread_count === 'number') {
                        updateNotifBadge(data.unread_count);
                    }
                })
                .catch(function () { notifBadgeRefreshPending = false; });
        }

        function addNotifItem(n) {
            if (!notifList) return false;
            if (n.id) {
                if (notifSeenIds[n.id]) return false;
                notifSeenIds[n.id] = true;
            }
            var emptyMsg = notifList.querySelector('.text-muted.py-3');
            if (emptyMsg) emptyMsg.remove();
            var html = renderNotifItem(n);
            notifList.insertAdjacentHTML('afterbegin', html);
            return true;
        }

        // Absolute base for app API calls. Derived from this file's own URL so
        // requests work from any page depth (/admin/..., /staff/..., root ...).
        var appBase = (function () {
            var s = document.querySelector('script[src*="assets/js/main.js"]');
            if (!s || !s.src) return '';
            return s.src.replace(/\/assets\/js\/main\.js(?:\?.*)?$/, '');
        })();

        function initSSE() {
            // One stream per page: reopening the dropdown used to tear the
            // connection down and start a fresh one every time, which threw
            // away the cursor and re-delivered whatever sat in the catch-up
            // window. The onerror handler below is what tears it down.
            if (notifEventSource) return;
            if (!notifBellBtn || typeof EventSource === 'undefined') return;

            notifEventSource = new EventSource(appBase + '/api/notifications_sse.php');

            notifEventSource.onmessage = function (e) {
                try {
                    var data = JSON.parse(e.data);
                    if (data.type === 'init') {
                        updateNotifBadge(data.unread_count || 0);
                    } else if (data.type === 'ping') {
                        // Keep-alive, no action needed
                    } else {
                        // Only a row that actually landed may trigger a badge
                        // refresh, so a replayed or duplicate event cannot
                        // inflate the count - and the count itself always
                        // comes from the server.
                        if (addNotifItem(data)) {
                            refreshNotifBadge();
                        }
                    }
                } catch (err) { /* ignore malformed messages */ }
            };

            notifEventSource.onerror = function () {
                if (notifEventSource) notifEventSource.close();
                notifEventSource = null;
                setTimeout(initSSE, 5000);
            };
        }

        if (notifBellBtn) {
            notifBellBtn.addEventListener('shown.bs.dropdown', function () {
                initSSE();
            });
        }

        // Delegate mark-as-read clicks in the dropdown
        document.addEventListener('click', function (e) {
            var markLink = e.target.closest('.mark-read');
            if (markLink) {
                e.preventDefault();
                var id = markLink.getAttribute('data-id');
                // Phase 34: the port's web middleware verifies CSRF on this
                // JSON POST (the reference checks no token anywhere), so the
                // shared XSRF-TOKEN cookie rides along as the standard
                // X-XSRF-TOKEN header - same strengthening as the @csrf
                // tokens added to the original forms.
                var xsrf = (document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/) || [])[1] || '';
                fetch(appBase + '/api/notifications.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-XSRF-TOKEN': decodeURIComponent(xsrf)
                    },
                    body: JSON.stringify({ action: 'mark_read', id: parseInt(id, 10) })
                }).then(function (r) { return r.json(); }).then(function (data) {
                    // Feature 2 (scoping): the API reports the caller's own
                    // remaining unread count - blanking the badge outright hid
                    // every other unread row from this same user.
                    var remaining = data && typeof data.unread_count === 'number' ? data.unread_count : 0;
                    updateNotifBadge(remaining);

                    // Reflect the read state in place: this row stops being
                    // bold/bordered and its Mark read link goes away, so the
                    // entry cannot be posted a second time.
                    var item = markLink.closest('.dropdown-item-text');
                    if (item) {
                        item.classList.remove('border-start', 'border-4', 'border-primary');
                        var title = item.querySelector('.small');
                        if (title) title.classList.remove('fw-bold');
                    }
                    markLink.remove();
                });
            }
        });
    });

    window.printSection = function (id) {
        var el = document.getElementById(id);
        if (!el) { window.print(); return; }
        var w = window.open('', '', 'width=800,height=900');
        w.document.write('<html><head><title>Print</title>');
        w.document.write('<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">');
        w.document.write('<style>body{padding:24px;}</style>');
        w.document.write('</head><body>');
        w.document.write(el.innerHTML);
        w.document.write('</body></html>');
        w.document.close();
        w.focus();
        setTimeout(function () { w.print(); w.close(); }, 400);
    };
})();