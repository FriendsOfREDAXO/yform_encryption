/**
 * YForm Encryption - Backend JavaScript
 *
 * Handles bulk operations and UI interactions.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        initTableGroupToggles();
        initSelectAllCheckboxes();
        initSessionTimer();
    });

    /**
     * Init toggle functionality for table groups.
     */
    function initTableGroupToggles() {
        var groups = document.querySelectorAll('.yform-encryption-table-group h4');
        groups.forEach(function (heading) {
            heading.style.cursor = 'pointer';
            heading.addEventListener('click', function (e) {
                // Don't toggle if clicking on a link
                if (e.target.tagName === 'A') {
                    return;
                }
                var group = heading.closest('.yform-encryption-table-group');
                if (!group) {
                    return;
                }
                var table = group.querySelector('.table');
                var bulkActions = group.querySelector('.yform-encryption-bulk-actions');

                if (table) {
                    table.style.display = table.style.display === 'none' ? '' : 'none';
                }
                if (bulkActions) {
                    bulkActions.style.display = bulkActions.style.display === 'none' ? '' : 'none';
                }
            });
        });
    }

    /**
     * Add "Select All" checkbox functionality to each table group header row.
     */
    function initSelectAllCheckboxes() {
        var headerRows = document.querySelectorAll('.yform-encryption-table-group thead tr');

        headerRows.forEach(function (headerRow) {
            var firstTh = headerRow.querySelector('th');
            if (!firstTh) {
                return;
            }

            var selectAll = document.createElement('input');
            selectAll.type = 'checkbox';
            selectAll.title = 'Alle auswählen';

            var group = headerRow.closest('.yform-encryption-table-group');
            if (!group) {
                return;
            }

            var checkboxes = group.querySelectorAll('tbody input[type="checkbox"]');

            // Check if all are already checked
            var allChecked = true;
            checkboxes.forEach(function (cb) {
                if (!cb.checked) {
                    allChecked = false;
                }
            });
            selectAll.checked = allChecked && checkboxes.length > 0;

            selectAll.addEventListener('change', function () {
                checkboxes.forEach(function (cb) {
                    cb.checked = selectAll.checked;
                });
            });

            firstTh.textContent = '';
            firstTh.appendChild(selectAll);
        });
    }

    /**
     * Init countdown timer for session guard.
     * Reads remaining seconds from a script-tag's data attributes
     * and ticks down every second. Reloads page when time expires.
     */
    function initSessionTimer() {
        var dataEl = document.getElementById('yform-enc-timer-data');
        if (!dataEl) {
            return;
        }

        var remaining = parseInt(dataEl.getAttribute('data-yform-enc-remaining'), 10);
        var pageUrl = dataEl.getAttribute('data-yform-enc-page');
        var timerEl = document.getElementById('yform-enc-timer');

        if (!timerEl || isNaN(remaining)) {
            return;
        }

        function pad(n) {
            return n < 10 ? '0' + n : '' + n;
        }

        function updateDisplay() {
            var m = Math.floor(remaining / 60);
            var s = remaining % 60;
            timerEl.textContent = pad(m) + ':' + pad(s);

            if (remaining <= 60) {
                timerEl.style.color = '#d9534f';
            } else if (remaining <= 300) {
                timerEl.style.color = '#f0ad4e';
            }
        }

        var interval = setInterval(function () {
            remaining--;

            if (remaining <= 0) {
                clearInterval(interval);
                // Session abgelaufen – Seite neu laden
                if (pageUrl) {
                    window.location.href = pageUrl;
                } else {
                    window.location.reload();
                }
                return;
            }

            updateDisplay();
        }, 1000);

        updateDisplay();
    }
})();
