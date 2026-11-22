// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Class overview: choosing a course in the pin selector pins it right away and refreshes only
 * that row, without reloading the page (design, 4.4). Unpinning works the same way.
 *
 * Without JavaScript the forms post to pin.php and the page reloads.
 *
 * Written as a classic AMD module, so amd/build/klasoverzicht.min.js can be a copy of this file
 * as long as there is no grunt on the test VM.
 *
 * @module     local_leerlingendashboard/klasoverzicht
 * @copyright  2026 Michel Labruyère
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/templates', 'core/notification', 'core/toast', 'core/pending'],
function(Ajax, Templates, Notification, Toast, Pending) {
    'use strict';

    var SELECTORS = {
        REGION: '[data-region="local_leerlingendashboard-klasoverzicht"]',
        ROW: '[data-region="student-row"]',
        PINFORM: 'form[data-action="pin-course"]',
        UNPINFORM: 'form[data-action="unpin-course"]'
    };

    /**
     * Enable or disable the controls of a row while a request is running.
     *
     * @param {HTMLElement} row
     * @param {Boolean} disabled
     */
    var setDisabled = function(row, disabled) {
        row.querySelectorAll('select, button').forEach(function(element) {
            element.disabled = disabled;
        });
    };

    /**
     * Pin or unpin through the web service and replace the row with the refreshed one.
     *
     * @param {HTMLElement} form The pin or unpin form.
     * @param {String} methodname Web service.
     * @param {String} courseid The course.
     */
    var change = function(form, methodname, courseid) {
        var row = form.closest(SELECTORS.ROW);
        var pending = new Pending('local_leerlingendashboard/klasoverzicht:change');
        setDisabled(row, true);

        Ajax.call([{
            methodname: methodname,
            args: {
                userid: parseInt(row.dataset.userid, 10),
                courseid: parseInt(courseid, 10),
                cohortid: parseInt(row.dataset.cohortid, 10)
            }
        }])[0].then(function(result) {
            if (result.html) {
                Templates.replaceNode(row, result.html, '');
            } else {
                row.remove();
            }
            Toast.add(result.message);
            pending.resolve();
            return result;
        }, function(error) {
            setDisabled(row, false);
            Notification.exception(error);
            pending.resolve();
        });
    };

    return {
        /**
         * Listen to the pin selectors and unpin buttons of the class overview.
         */
        init: function() {
            var region = document.querySelector(SELECTORS.REGION);
            if (!region || region.dataset.initialised) {
                return;
            }
            region.dataset.initialised = '1';

            // Choosing a course pins it right away.
            region.addEventListener('change', function(e) {
                var select = e.target.closest(SELECTORS.PINFORM + ' select');
                if (select && select.value) {
                    change(select.form, 'local_leerlingendashboard_pin_course', select.value);
                }
            });

            // Submitting a form (unpin button, or Enter in the selector) goes through the web service too.
            region.addEventListener('submit', function(e) {
                var form = e.target;
                if (form.matches(SELECTORS.PINFORM)) {
                    e.preventDefault();
                    var select = form.querySelector('select');
                    if (select.value) {
                        change(form, 'local_leerlingendashboard_pin_course', select.value);
                    }
                } else if (form.matches(SELECTORS.UNPINFORM)) {
                    e.preventDefault();
                    change(form, 'local_leerlingendashboard_unpin_course', form.elements.courseid.value);
                }
            });
        }
    };
});
