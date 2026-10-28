// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * The criteria editor in the Marking criteria check.
 *
 * The plugin proposes each question's criteria; the assessor reviews them here, edits them
 * if needed, and confirms. Only confirmed criteria drive the per-criterion breakdown. The
 * server checks everything again: this editor only makes a mistake visible before saving.
 *
 * @module     quiz_aigrader/criteria_editor
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

    const MAX_ROWS = 10;
    const LABEL_LENGTH = 200;

    let config = {};

    /**
     * Fill a string's placeholders.
     *
     * @param {string} id String identifier.
     * @param {*} a A value for {$a}, or an object for {$a->name}.
     * @returns {string}
     */
    function str(id, a) {
        let text = (config.strings && config.strings[id]) || id;
        if (a !== null && typeof a === 'object') {
            Object.keys(a).forEach(key => {
                text = text.split('{$a->' + key + '}').join(String(a[key]));
            });
        } else if (a !== undefined) {
            text = text.split('{$a}').join(String(a));
        }
        return text;
    }

    /**
     * Format a mark: no trailing zeros.
     *
     * @param {number} value
     * @returns {string}
     */
    function fmt(value) {
        return String(Math.round(value * 100) / 100);
    }

    /**
     * Read a mark typed by a person, accepting a decimal comma.
     *
     * @param {string} value
     * @returns {number} NaN when it is not a number.
     */
    function readMark(value) {
        const text = String(value || '').trim().replace(',', '.');
        if (text === '' || !/^\d*\.?\d+$/.test(text)) {
            return NaN;
        }
        return parseFloat(text);
    }

    /**
     * The key scores are matched by, as the server makes it: two criteria with the same key
     * would be confused, so they cannot both be confirmed.
     *
     * @param {string} label
     * @returns {string}
     */
    function keyOf(label) {
        return String(label).replace(/[^\p{L}\p{N}]+/gu, '_').toLowerCase().replace(/^_+|_+$/g, '');
    }

    /**
     * Tidy a criterion's spacing as the server does.
     *
     * @param {string} label
     * @returns {string}
     */
    function tidy(label) {
        // Control characters are matched on purpose: they are tidied away, as on the server.
        // eslint-disable-next-line no-control-regex
        return String(label || '').replace(/[\u0000-\u001F\u007F\u00A0\u2000-\u200B\u202F\u3000\s]+/g, ' ').trim();
    }

    /**
     * Send a request to the plugin's endpoint.
     *
     * @param {Object} params
     * @returns {Promise<Object>}
     */
    function post(params) {
        const body = new URLSearchParams();
        body.append('cmid', config.cmid);
        body.append('sesskey', config.sesskey);
        Object.keys(params).forEach(key => body.append(key, params[key]));
        return fetch(config.ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: body.toString()
        }).then(response => response.json());
    }

    /**
     * Make an element.
     *
     * @param {string} tag
     * @param {string} className
     * @param {string} text
     * @returns {HTMLElement}
     */
    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    }

    /**
     * The proposal stored on a question's row.
     *
     * @param {HTMLElement} row
     * @returns {Array}
     */
    function proposalOf(row) {
        try {
            const rows = JSON.parse(row.dataset.proposal || '[]');
            return Array.isArray(rows) ? rows : [];
        } catch (e) {
            return [];
        }
    }

    /**
     * Check what is in the editor.
     *
     * @param {Array} rows Each {label, max} as typed.
     * @param {number} maxmark
     * @returns {{error: string, sum: number}} The first problem, or an empty string.
     */
    function check(rows, maxmark) {
        let sum = 0;
        const seen = {};
        let error = '';
        rows.forEach(r => {
            const label = tidy(r.label);
            const key = keyOf(label);
            const mark = readMark(r.max);
            if (!error && key === '') {
                error = str('critedit_error_label');
            }
            if (!error && key !== '' && seen[key]) {
                error = str('critedit_error_duplicate');
            }
            seen[key] = true;
            if (!error && (isNaN(mark) || Math.round(mark * 100) <= 0)) {
                error = str('critedit_error_mark');
            }
            if (!isNaN(mark)) {
                sum += Math.round(mark * 100) / 100;
            }
        });
        if (!error && Math.abs(sum - maxmark) > 0.005) {
            error = str('critedit_error_total', fmt(maxmark));
        }
        return {error: error, sum: sum};
    }

    /**
     * Show a question's state in its row of the panel.
     *
     * @param {HTMLElement} row
     * @param {string} state 'confirmed' or 'required'.
     * @param {Array} criteria The confirmed criteria, when confirmed.
     * @param {string} confirmedtext Who confirmed it and when.
     * @param {number} since When it was confirmed, sent back with the next change.
     */
    function showState(row, state, criteria, confirmedtext, since) {
        row.dataset.state = state;
        row.dataset.since = String(since || 0);
        const confirmed = state === 'confirmed';
        row.classList.toggle('aigrader-critcheck-flagged', !confirmed);
        if (confirmed) {
            row.dataset.exact = '0';
            row.dataset.proposal = JSON.stringify(criteria);
        }

        const pill = row.querySelector('.aigrader-critcheck-statecell .aigrader-critcheck-pill');
        if (pill) {
            pill.textContent = confirmed ? str('criteriacheck_confirmed', criteria.length) : str('criteriacheck_toconfirm');
            pill.classList.toggle('aigrader-critcheck-pill-attention', !confirmed);
        }

        const cell = row.querySelector('.aigrader-critcheck-notescell');
        const actions = cell.querySelector('.aigrader-critcheck-actions');
        if (confirmed) {
            // The panel now lists exactly what was confirmed, and by whom.
            Array.from(cell.children).forEach(child => {
                if (child !== actions) {
                    child.remove();
                }
            });
            const labels = el('span', 'aigrader-critcheck-labels');
            criteria.forEach((c, i) => {
                if (i > 0) {
                    labels.appendChild(document.createTextNode(' · '));
                }
                labels.appendChild(document.createTextNode(c.label + ' '));
                labels.appendChild(el('span', 'aigrader-critcheck-weight', fmt(c.max)));
            });
            cell.insertBefore(labels, actions);
            cell.insertBefore(el('span', 'aigrader-critcheck-by', confirmedtext || ''), actions);
        } else {
            // Withdrawn: what is listed is now a proposal again.
            const by = cell.querySelector('.aigrader-critcheck-by');
            if (by) {
                by.remove();
            }
            const labels = cell.querySelector('.aigrader-critcheck-labels');
            if (labels && !labels.querySelector('.aigrader-critcheck-lead')) {
                labels.insertBefore(el('span', 'aigrader-critcheck-lead', str('criteriacheck_proposed')), labels.firstChild);
                labels.insertBefore(document.createTextNode(' '), labels.children[0].nextSibling);
            }
        }

        const button = row.querySelector('.aigrader-critcheck-review');
        if (button) {
            button.textContent = confirmed ? str('criteriacheck_editconfirmed') : str('criteriacheck_confirm');
            button.classList.toggle('aigrader-critcheck-review-primary', !confirmed);
        }
        updateSummary();
    }

    /**
     * Update the panel's summary and the bulk button after a change.
     */
    function updateSummary() {
        const panel = document.getElementById('aigrader-critcheck');
        if (!panel) {
            return;
        }
        const rows = Array.from(panel.querySelectorAll('tr.aigrader-critcheck-row'));
        const issues = rows.filter(r => r.dataset.state !== 'confirmed').length;
        const status = panel.querySelector('.aigrader-critcheck-status');
        if (status) {
            status.textContent = issues
                ? str('criteriacheck_issues_confirm', {issues: issues, total: rows.length})
                : str('criteriacheck_ok_confirm', rows.length);
        }
        panel.classList.toggle('aigrader-critcheck-has-issues', issues > 0);
        const exact = rows.filter(r => r.dataset.exact === '1' && r.dataset.state !== 'confirmed').length;
        const bulk = panel.querySelector('.aigrader-critcheck-bulk');
        if (bulk && exact === 0) {
            bulk.remove();
        }
    }

    /**
     * Close any editor open under a row.
     *
     * @param {HTMLElement} row
     * @returns {boolean} Whether one was open.
     */
    function closeEditor(row) {
        const next = row.nextElementSibling;
        if (next && next.classList.contains('aigrader-critedit-row')) {
            next.remove();
            row.classList.remove('aigrader-critcheck-editing');
            return true;
        }
        return false;
    }

    /**
     * Open the editor under a question's row.
     *
     * @param {HTMLElement} row
     */
    function openEditor(row) {
        if (closeEditor(row)) {
            return;
        }
        const maxmark = parseFloat(row.dataset.maxmark);
        const questionid = row.dataset.questionid;
        let rows = proposalOf(row).map(r => ({
            label: String(r.label || ''),
            max: r.max === null || r.max === undefined ? '' : fmt(r.max)
        }));
        if (rows.length === 0) {
            const start = Math.max(1, Math.min(config.min, Math.floor(maxmark)));
            for (let i = 0; i < start; i++) {
                rows.push({label: '', max: ''});
            }
        }

        const tr = el('tr', 'aigrader-critedit-row');
        const td = el('td');
        td.colSpan = row.children.length;
        tr.appendChild(td);
        const box = el('div', 'aigrader-critedit');
        td.appendChild(box);
        box.appendChild(el('p', 'aigrader-critedit-intro', str('critedit_intro')));

        const list = el('div', 'aigrader-critedit-list');
        list.setAttribute('role', 'group');
        box.appendChild(list);

        const head = el('div', 'aigrader-critedit-head');
        head.appendChild(el('span', 'aigrader-critedit-col-label', str('critedit_criterion')));
        head.appendChild(el('span', 'aigrader-critedit-col-mark', str('critedit_marks')));
        list.appendChild(head);

        const lines = el('div', 'aigrader-critedit-lines');
        list.appendChild(lines);

        const add = el('button', 'aigrader-critedit-add', str('critedit_add'));
        add.type = 'button';
        box.appendChild(add);

        const footer = el('div', 'aigrader-critedit-footer');
        const total = el('span', 'aigrader-critedit-total');
        const hint = el('span', 'aigrader-critedit-hint');
        const message = el('div', 'aigrader-critedit-message');
        message.setAttribute('role', 'alert');
        const scale = el('button', 'aigrader-critedit-scale');
        scale.type = 'button';
        footer.appendChild(total);
        footer.appendChild(scale);
        footer.appendChild(hint);
        box.appendChild(footer);
        box.appendChild(message);

        const buttons = el('div', 'aigrader-critedit-buttons');
        const save = el('button', 'aigrader-critedit-save', str('critedit_save'));
        save.type = 'button';
        const cancel = el('button', 'aigrader-critedit-cancel', str('critedit_cancel'));
        cancel.type = 'button';
        buttons.appendChild(save);
        buttons.appendChild(cancel);
        let reset = null;
        if (row.dataset.state === 'confirmed') {
            reset = el('button', 'aigrader-critedit-reset', str('critedit_reset'));
            reset.type = 'button';
            buttons.appendChild(reset);
        }
        box.appendChild(buttons);

        /**
         * Read the editor's rows.
         *
         * @returns {Array}
         */
        const read = () => Array.from(lines.querySelectorAll('.aigrader-critedit-line')).map(line => ({
            label: line.querySelector('.aigrader-critedit-label').value,
            max: line.querySelector('.aigrader-critedit-mark').value
        }));

        /**
         * Show the total, the recommended range and the first problem.
         */
        const refresh = () => {
            const current = read();
            const result = check(current, maxmark);
            total.textContent = str('critedit_total', {sum: fmt(result.sum), max: fmt(maxmark)});
            total.classList.toggle('aigrader-critedit-total-off', Math.abs(result.sum - maxmark) > 0.005);
            hint.textContent = current.length < config.min || current.length > config.max
                ? str('critedit_hint', {min: config.min, max: config.max})
                : '';
            message.textContent = result.error;
            save.disabled = result.error !== '';
            // Every mark readable and above 0, but not adding up: offer to share the question's
            // mark in the same proportions, as when guidance written for 2 marks sits on a
            // question worth 1 in this quiz.
            const readable = current.length > 0 && current.every(r => readMark(r.max) > 0);
            const off = Math.abs(result.sum - maxmark) > 0.005;
            scale.hidden = !(readable && off && result.sum > 0);
            scale.textContent = str('critedit_scale', fmt(maxmark));
            add.disabled = current.length >= MAX_ROWS;
            lines.querySelectorAll('.aigrader-critedit-remove').forEach(b => {
                b.disabled = current.length <= 1;
            });
        };

        /**
         * Add one line to the editor.
         *
         * @param {Object} value {label, max}
         * @returns {HTMLElement}
         */
        const addLine = value => {
            const line = el('div', 'aigrader-critedit-line');
            const label = el('input', 'aigrader-critedit-label');
            label.type = 'text';
            label.maxLength = LABEL_LENGTH;
            label.value = value.label;
            label.setAttribute('aria-label', str('critedit_criterion'));
            label.placeholder = str('critedit_criterion');
            const mark = el('input', 'aigrader-critedit-mark');
            mark.type = 'text';
            mark.inputMode = 'decimal';
            mark.value = value.max;
            mark.setAttribute('aria-label', str('critedit_marks'));
            mark.placeholder = str('critedit_marks');
            const remove = el('button', 'aigrader-critedit-remove', str('critedit_remove'));
            remove.type = 'button';
            remove.addEventListener('click', () => {
                line.remove();
                refresh();
            });
            label.addEventListener('input', refresh);
            mark.addEventListener('input', refresh);
            line.appendChild(label);
            line.appendChild(mark);
            line.appendChild(remove);
            lines.appendChild(line);
            return line;
        };

        rows.forEach(addLine);
        scale.addEventListener('click', () => {
            const inputs = Array.from(lines.querySelectorAll('.aigrader-critedit-mark'));
            const values = inputs.map(i => readMark(i.value));
            const sum = values.reduce((a, v) => a + v, 0);
            if (!(sum > 0)) {
                return;
            }
            // Two decimal places; whatever rounding leaves over goes to the largest criterion, so
            // no criterion is pushed to zero by it.
            const shares = values.map(v => Math.round(v * maxmark / sum * 100) / 100);
            const left = Math.round((maxmark - shares.reduce((a, v) => a + v, 0)) * 100) / 100;
            const largest = shares.indexOf(Math.max.apply(null, shares));
            shares[largest] = Math.round((shares[largest] + left) * 100) / 100;
            shares.forEach((share, i) => {
                inputs[i].value = fmt(share);
            });
            refresh();
            save.focus();
        });
        add.addEventListener('click', () => {
            addLine({label: '', max: ''}).querySelector('.aigrader-critedit-label').focus();
            refresh();
        });
        cancel.addEventListener('click', () => closeEditor(row));

        save.addEventListener('click', () => {
            const current = read();
            if (check(current, maxmark).error !== '') {
                return;
            }
            save.disabled = true;
            save.textContent = str('critedit_saving');
            post({
                action: 'confirmcriteria',
                questionid: questionid,
                maxmark: row.dataset.maxmark,
                since: row.dataset.since || '0',
                criteria: JSON.stringify(current.map(r => ({label: tidy(r.label), max: r.max})))
            }).then(resp => {
                if (!resp || !resp.ok) {
                    message.textContent = (resp && resp.message) || str('critedit_failed');
                    save.textContent = str('critedit_save');
                    save.disabled = false;
                    return;
                }
                closeEditor(row);
                showState(row, 'confirmed', resp.criteria, resp.confirmedtext, resp.since);
                const button = row.querySelector('.aigrader-critcheck-review');
                if (button) {
                    button.focus();
                }
            }).catch(() => {
                message.textContent = str('critedit_failed');
                save.textContent = str('critedit_save');
                save.disabled = false;
            });
        });

        if (reset) {
            reset.addEventListener('click', () => {
                reset.disabled = true;
                post({
                    action: 'resetcriteria',
                    questionid: questionid,
                    maxmark: row.dataset.maxmark,
                    since: row.dataset.since || '0'
                }).then(resp => {
                    if (!resp || !resp.ok) {
                        message.textContent = (resp && resp.message) || str('critedit_failed');
                        reset.disabled = false;
                        return;
                    }
                    closeEditor(row);
                    showState(row, 'required', [], '', 0);
                }).catch(() => {
                    message.textContent = str('critedit_failed');
                    reset.disabled = false;
                });
            });
        }

        row.parentNode.insertBefore(tr, row.nextSibling);
        row.classList.add('aigrader-critcheck-editing');
        refresh();
        const first = lines.querySelector('.aigrader-critedit-label');
        if (first) {
            first.focus();
        }
    }

    /**
     * Confirm, one after another, every question whose criteria were read exactly.
     *
     * @param {HTMLElement} button
     */
    function confirmAll(button) {
        const panel = document.getElementById('aigrader-critcheck');
        const rows = Array.from(panel.querySelectorAll('tr.aigrader-critcheck-row'))
            .filter(r => r.dataset.exact === '1' && r.dataset.state !== 'confirmed');
        button.disabled = true;
        const failures = [];
        const next = () => {
            const row = rows.shift();
            if (!row) {
                updateSummary();
                failures.forEach(([failed, text]) => {
                    const cell = failed.querySelector('.aigrader-critcheck-notescell');
                    const note = el('p', 'aigrader-critcheck-failed', str('critedit_notconfirmed', text));
                    note.setAttribute('role', 'alert');
                    cell.insertBefore(note, cell.querySelector('.aigrader-critcheck-actions'));
                });
                if (failures.length) {
                    openEditor(failures[0][0]);
                }
                return Promise.resolve();
            }
            const proposal = proposalOf(row);
            return post({
                action: 'confirmcriteria',
                questionid: row.dataset.questionid,
                maxmark: row.dataset.maxmark,
                since: row.dataset.since || '0',
                bulk: 1,
                criteria: JSON.stringify(proposal)
            }).then(resp => {
                if (resp && resp.ok) {
                    closeEditor(row);
                    showState(row, 'confirmed', resp.criteria, resp.confirmedtext, resp.since);
                } else {
                    // Say why on the question's own row, and open it for the assessor.
                    row.dataset.exact = '0';
                    failures.push([row, (resp && resp.message) || str('critedit_failed')]);
                }
                return next();
            });
        };
        next().catch(() => {
            button.disabled = false;
        });
    }

    return {
        /**
         * Start the editor.
         *
         * @param {Object} options cmid, sesskey, ajaxurl, min and max. The wording is on the panel.
         */
        init: function(options) {
            config = options || {};
            const panel = document.getElementById('aigrader-critcheck');
            if (!panel) {
                return;
            }
            try {
                config.strings = JSON.parse(panel.dataset.strings || '{}');
            } catch (e) {
                config.strings = {};
            }
            panel.addEventListener('click', e => {
                const review = e.target.closest('.aigrader-critcheck-review');
                if (review) {
                    openEditor(review.closest('tr'));
                    return;
                }
                const all = e.target.closest('.aigrader-critcheck-confirmall');
                if (all) {
                    confirmAll(all);
                }
            });
            panel.addEventListener('keydown', e => {
                if (e.key === 'Escape') {
                    const editor = e.target.closest('.aigrader-critedit-row');
                    if (editor && editor.previousElementSibling) {
                        const row = editor.previousElementSibling;
                        closeEditor(row);
                        const button = row.querySelector('.aigrader-critcheck-review');
                        if (button) {
                            button.focus();
                        }
                    }
                }
            });
        }
    };
});
