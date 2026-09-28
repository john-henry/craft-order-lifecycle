(function() {
    'use strict';

    /**
     * Writes each row's position back into its hidden order input, so the
     * posted column order matches what is on screen.
     */
    function updateColumnOrder() {
        const rows = document.querySelectorAll('#exportColumnsList tr');

        rows.forEach(function(row, index) {
            const input = row.querySelector('.order-input');

            if (input) {
                input.value = index;
            }
        });
    }

    /**
     * Sets a Craft dateTimeField. Craft renders the date input as #fieldId-date
     * and the time input as #fieldId-time, and listens for a change on each.
     */
    function setDateTimeField(fieldId, date) {
        const pad = function(n) {
            return String(n).padStart(2, '0');
        };

        const values = {
            date: date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()),
            time: pad(date.getHours()) + ':' + pad(date.getMinutes()),
        };

        Object.keys(values).forEach(function(part) {
            const input = document.getElementById(fieldId + '-' + part);

            if (input) {
                input.value = values[part];
                input.dispatchEvent(new Event('change', {bubbles: true}));
            }
        });
    }

    /** Returns the from/to dates a preset covers. */
    function presetRange(preset) {
        const now = new Date();
        const from = new Date();
        const to = new Date();

        switch (preset) {
            case 'today':
                from.setHours(0, 0, 0, 0);
                to.setHours(23, 59, 59, 999);
                break;

            case 'yesterday':
                from.setDate(from.getDate() - 1);
                from.setHours(0, 0, 0, 0);
                to.setDate(to.getDate() - 1);
                to.setHours(23, 59, 59, 999);
                break;

            case 'last7':
                from.setDate(from.getDate() - 7);
                break;

            case 'last30':
                from.setDate(from.getDate() - 30);
                break;

            case 'last90':
                from.setDate(from.getDate() - 90);
                break;

            case 'thisMonth':
                return [
                    new Date(now.getFullYear(), now.getMonth(), 1),
                    new Date(now.getFullYear(), now.getMonth() + 1, 0, 23, 59, 59),
                ];

            case 'lastMonth':
                return [
                    new Date(now.getFullYear(), now.getMonth() - 1, 1),
                    new Date(now.getFullYear(), now.getMonth(), 0, 23, 59, 59),
                ];

            case 'thisYear':
                return [
                    new Date(now.getFullYear(), 0, 1),
                    new Date(now.getFullYear(), 11, 31, 23, 59, 59),
                ];

            case 'last365':
                from.setDate(from.getDate() - 365);
                break;

            case 'all':
                return [new Date('2000-01-01'), new Date()];
        }

        return [from, to];
    }

    function initColumnSort() {
        const rows = document.querySelectorAll('#exportColumnsList tr');

        if (rows.length === 0) {
            return;
        }

        // Garnish runs what it is given through jQuery's makeArray, which
        // treats a string as one entry rather than a selector to resolve, so
        // this has to be the elements. A NodeList is array-like, which is all
        // makeArray wants, so no jQuery is needed to hand them over.
        new Garnish.DragSort(rows, {
            handle: '.move',
            axis: 'y',
            onSortChange: updateColumnOrder,
        });

        updateColumnOrder();
    }

    /** Toggles each row's Move up/down menu items off at the ends of the list. */
    function updateColumnMoveItems() {
        const rows = Array.from(document.querySelectorAll('#exportColumnsList tr'));
        const lastIndex = rows.length - 1;

        rows.forEach(function(row, index) {
            const upItem = row.querySelector('[data-action="moveUp"]');
            const downItem = row.querySelector('[data-action="moveDown"]');

            if (upItem && upItem.closest('li')) {
                upItem.closest('li').classList.toggle('hidden', index === 0);
            }

            if (downItem && downItem.closest('li')) {
                downItem.closest('li').classList.toggle('hidden', index === lastIndex);
            }
        });
    }

    /**
     * Moves a column row up or down, the keyboard equivalent of dragging it
     * by the handle. Reuses the same DOM move and order-input update the
     * drag path uses, so the two stay equivalent.
     */
    function moveColumnRow(row, direction) {
        const sibling = direction === 'up' ? row.previousElementSibling : row.nextElementSibling;

        if (!sibling) {
            return;
        }

        if (direction === 'up') {
            row.parentNode.insertBefore(row, sibling);
        } else {
            row.parentNode.insertBefore(sibling, row);
        }

        updateColumnOrder();
        updateColumnMoveItems();

        const rows = Array.from(document.querySelectorAll('#exportColumnsList tr'));
        const position = rows.indexOf(row) + 1;

        if (window.Craft && Craft.cp && typeof Craft.cp.announce === 'function') {
            Craft.cp.announce(Craft.t('order-lifecycle', 'Column moved to position {position} of {total}.', {
                position: position,
                total: rows.length,
            }));
        }
    }

    /** Wires up each row's Move up/down action menu items. */
    function initColumnActionMenus() {
        const list = document.getElementById('exportColumnsList');

        if (!list) {
            return;
        }

        list.addEventListener('click', function(e) {
            const target = e.target.closest('[data-action="moveUp"], [data-action="moveDown"]');

            if (!target) {
                return;
            }

            const row = target.closest('tr');

            if (!row) {
                return;
            }

            moveColumnRow(row, target.dataset.action === 'moveUp' ? 'up' : 'down');
        });

        updateColumnMoveItems();
    }

    function initDatePresets() {
        const buttons = document.querySelectorAll('.date-preset-btn');

        buttons.forEach(function(button) {
            button.addEventListener('click', function(e) {
                e.preventDefault();

                buttons.forEach(function(other) {
                    other.classList.remove('active');
                });
                button.classList.add('active');

                const range = presetRange(button.dataset.preset);

                setDateTimeField('dateFrom', range[0]);
                setDateTimeField('dateTo', range[1]);
            });
        });
    }

    function initSubmitGuard() {
        const form = document.getElementById('ol-export-form');

        if (!form) {
            return;
        }

        // The form opens in a new tab, so the page never navigates away and
        // there is no "response received" to re-enable on. A fixed delay is
        // what stops a second press landing while the first is still running.
        form.addEventListener('submit', function(e) {
            const button = document.getElementById('ol-export-submit');
            const spinner = document.getElementById('ol-export-spinner');

            if (!button) {
                return;
            }

            if (button.disabled) {
                e.preventDefault();
                return;
            }

            button.disabled = true;

            if (spinner) {
                spinner.classList.remove('hidden');
            }

            window.setTimeout(function() {
                button.disabled = false;

                if (spinner) {
                    spinner.classList.add('hidden');
                }
            }, 3000);
        });
    }

    function initSelectAll() {
        const toggle = document.getElementById('select-all-events');

        if (!toggle) {
            return;
        }

        toggle.addEventListener('click', function(e) {
            e.preventDefault();

            const boxes = Array.from(document.querySelectorAll('.event-checkbox'));
            const allChecked = boxes.length > 0 && boxes.every(function(box) {
                return box.checked;
            });

            boxes.forEach(function(box) {
                box.checked = !allChecked;
            });

            toggle.textContent = allChecked
                ? Craft.t('order-lifecycle', 'Select All')
                : Craft.t('order-lifecycle', 'Deselect All');
        });
    }

    function init() {
        initColumnSort();
        initColumnActionMenus();
        initDatePresets();
        initSubmitGuard();
        initSelectAll();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
