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
 * AI Grader  -  clean AMD JS module.
 *
 * Handles:
 *  - Fetching credits
 *  - Grading individual essays
 *  - Grading all essays
 *  - Expand/collapse answers
 *
 * @module     quiz_aigrader/aigrader
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/str', 'core/notification', 'core/log'], function($, Str, Notification, Log) {

    let config = {};
    let strings = {};

    /**
     * Write a diagnostic line, if the site is in developer debug mode.
     *
     * Diagnostic logging, off unless the site is in developer debug mode.
     * These lines carry the graded response and the feedback written about it. That is a
     * learner's work, so it does not belong in the browser console of every assessor on a
     * production site; and core/log is Moodle's own logger, which is what a reviewer
     * expects to see rather than a bare console call.
     *
     * @param {String} msg What happened.
     * @param {*} [data] The payload to log beside it.
     * @return {void}
     */
    function log(msg, data) {
        if (!config.debug) {
            return;
        }
        Log.debug('[AI Grader] ' + msg, data ?? '');
    }

    /**
     * Show or hide the full-screen progress overlay.
     *
     * @param {Boolean} show True to show the overlay, false to hide it.
     * @param {String} [text] The message to show while it is up.
     * @return {void}
     */
    function showLoading(show, text) {
        const $overlay = $('#aigrader-loading');
        if (!$overlay.length) {
            return;
        }

        if (show) {
            $overlay.find('.aigrader-loading-text').text(text || strings.processing);
            $overlay.addClass('is-visible');
        } else {
            $overlay.removeClass('is-visible');
        }
    }

    /**
     * Replace any current alert with a new one above the report.
     *
     * @param {String} type One of success, warning, error or info; selects the icon and colour.
     * @param {String} title The heading, already translated.
     * @param {String} message The body, which may have come from the grading service.
     * @return {void}
     */
    function showAlert(type, title, message) {
        $('.aigrader-alert').remove();

        const typeClass = 'aigrader-alert-' + type;

        let icon = '';
        if (type === 'error') {
            icon = '<circle cx="12" cy="12" r="10"></circle>' +
                   '<line x1="9" y1="9" x2="15" y2="15"></line>' +
                   '<line x1="15" y1="9" x2="9" y2="15"></line>';
        } else if (type === 'success') {
            icon = '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>' +
                   '<polyline points="22 4 12 14.01 9 11.01"/>';
        } else {
            icon = '<circle cx="12" cy="12" r="10"></circle>' +
                   '<line x1="12" y1="8" x2="12" y2="12"></line>' +
                   '<line x1="12" y1="16" x2="12.01" y2="16"></line>';
        }

        const html =
            `<div class="aigrader-alert ${typeClass} ag-flex-center ag-gap-md">
                <svg class="aigrader-alert-icon ag-icon-md ag-icon-fixed" xmlns="http://www.w3.org/2000/svg"
                     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    ${icon}
                </svg>
                <div class="aigrader-alert-content">
                    <p class="aigrader-alert-title">${escapeHtml(title)}</p>
                    <p class="aigrader-alert-message">${escapeHtml(message)}</p>
                </div>
            </div>`;

        $('.aigrader-header').after(html);

        setTimeout(() => {
            $('.aigrader-alert').fadeOut(300, function() {
                $(this).remove();
            });
        }, 4000);
    }

    /**
     * Update the credits badge in the report header.
     *
     * @param {Number} credits Credits remaining.
     * @param {Boolean} [isUnlimited] True for an unmetered site, which shows an infinity symbol.
     * @return {void}
     */
    function updateCredits(credits, isUnlimited) {
        const badge = $('#aigrader-credits-badge');
        const count = $('#aigrader-credits-count');

        // Display "infinity" for unlimited credits
        if (isUnlimited || credits === -1) {
            count.text('infinity');
            badge.removeClass('aigrader-credits-low aigrader-credits-empty');
            badge.addClass('aigrader-credits-unlimited');
            return;
        }

        count.text(credits);

        badge.removeClass('aigrader-credits-low aigrader-credits-empty aigrader-credits-unlimited');

        if (credits === 0) {
            badge.addClass('aigrader-credits-empty');
        } else if (credits < 10) {
            badge.addClass('aigrader-credits-low');
        }
    }

    /**
     * Ask the server how many credits are left and update the badge.
     * @return {void}
     */
    function fetchCredits() {
        log('Fetching credits...');
        log('Using ajaxurl:', config.ajaxurl);

        $.post(config.ajaxurl, {
            action: 'credits_status',
            cmid: config.cmid,
            sesskey: config.sesskey
        }, null, 'json')
        .done(resp => {
            log('Credits response:', resp);

            if (resp.ok) {
                updateCredits(resp.credits, resp.isUnlimited);
                $('#aigrader-credits-badge').show();
            } else {
                $('#aigrader-credits-badge').hide();
                showAlert('warning', strings.error, resp.message || strings.error_fetching_credits);
            }
        })
        .fail(() => {
            $('#aigrader-credits-badge').hide();
            showAlert('error', strings.error, strings.error_connection);
        });
    }


    /* ---------------------------------------------------------
     * Feedback section headings
     *
     * One place decides what counts as a heading and which kind it is. Both the renderer
     * and the full-marks filter below call this, so there is no way for them to disagree
     * about where a section starts.
     * --------------------------------------------------------- */

    // Lines longer than this are prose, not headings. Without the limit a sentence such as
    // "There are no significant areas for improvement" opens a second improvements section.
    const HEADING_MAX_LENGTH = 80;

    /**
     * Classify one line.
     *
     * @param {String} line Trimmed line of feedback text.
     * @return {String|null} 'success', 'warning', 'info', or null when it is not a heading.
     */
    function detectSectionType(line) {
        if (line.length > HEADING_MAX_LENGTH) {
            return null;
        }

        // A heading is not a sentence. Requiring heading shape stops a line such as
        // "Your answer has real strengths." from opening a section mid-prose - which in
        // 'strengths' mode was actively harmful, because a spurious success heading
        // appearing after an improvements heading reset the filter and let the remaining
        // improvement text through to a learner on full marks.
        const bare = line.replace(/^[\s*#>\-\u2022]+/, '').replace(/[:\uff1a]\s*$/, '').trim();
        if (bare === '' || /[.!?\u3002\uff01\uff1f]$/.test(bare)) {
            return null;
        }

        if (/^(\[thumbs-up\]|what you did well|strengths|good work|well done)/i.test(bare)) {
            return 'success';
        }
        if (/^(\[chart-down\]|what needs improvement|areas for improvement|needs work|could improve)/i.test(bare)) {
            return 'warning';
        }
        if (/^(\[tip\]|\u2753|how to improve|suggestions|recommendations|next steps|tips|kaizen-houhou|kaizen-kaitou)/iu
                .test(bare)) {
            return 'info';
        }
        return null;
    }

    /* ---------------------------------------------------------
     * Marks
     *
     * Marks are handled as absolute values everywhere, never as a percentage of the
     * question's maximum. Routing a mark through an integer percentage cannot represent
     * 2/3 or 5/7: releases up to 4.3.0 stored 2.01 for a selected 2/3 and 4.97 for 5/7,
     * so the mark in the gradebook was not the mark the assessor chose. The percentage is
     * now derived from the mark for display only.
     * --------------------------------------------------------- */

    // A question's maximum mark is a float, so every comparison needs a tolerance.
    const MARK_EPSILON = 1e-9;

    // Refuse to build a scale longer than this. A misconfigured question with a huge
    // maximum would otherwise lock the browser building an unusable dropdown.
    const MARK_SCALE_MAX_OPTIONS = 200;

    /**
     * Round a mark to two decimal places, the precision Moodle stores marks at.
     *
     * @param {Number} value Raw mark.
     * @return {Number} Rounded mark.
     */
    function roundMark(value) {
        return Math.round(value * 100) / 100;
    }

    /**
     * Render a mark for display, without trailing zeros.
     *
     * @param {Number} value Mark.
     * @return {String} e.g. '3', '2.5'.
     */
    function formatMark(value) {
        return String(roundMark(value));
    }

    /**
     * Build the list of marks a question can be awarded.
     *
     * A whole-number maximum keeps whole-mark steps. A maximum that is not a whole number
     * steps in halves and always ends exactly on the maximum, so full marks are reachable:
     * the old integer loop offered 0, 1, 2 on a 2.5-mark question, making full marks
     * impossible, and offered only 0 on a 0.5-mark question.
     *
     * @param {Number} maxMark The question's maximum mark.
     * @return {Array} Ascending list of awardable marks, always including 0 and maxMark.
     */
    function buildMarkScale(maxMark) {
        const max = roundMark(maxMark);
        if (!(max > 0)) {
            return [];
        }
        const step = Number.isInteger(max) ? 1 : 0.5;
        const marks = [];
        for (let v = 0; v <= max + MARK_EPSILON && marks.length < MARK_SCALE_MAX_OPTIONS; v = roundMark(v + step)) {
            marks.push(v);
        }
        if (marks.length === 0 || Math.abs(marks[marks.length - 1] - max) > MARK_EPSILON) {
            marks.push(max);
        }
        return marks;
    }

    /**
     * Build the grade dropdown's options.
     *
     * Option values are absolute marks. The option closest to the suggested mark is
     * preselected, so a suggestion that does not land exactly on the scale still selects
     * the nearest awardable mark rather than silently falling back to zero.
     *
     * @param {Number} maxMark The question's maximum mark.
     * @param {Number} suggestedMark The mark the grading service suggested.
     * @return {String} HTML option elements.
     */
    function buildMarkOptions(maxMark, suggestedMark) {
        const marks = buildMarkScale(maxMark);
        if (marks.length === 0) {
            return '';
        }
        let closest = marks[0];
        if (typeof suggestedMark === 'number' && isFinite(suggestedMark)) {
            marks.forEach(function(m) {
                if (Math.abs(m - suggestedMark) < Math.abs(closest - suggestedMark)) {
                    closest = m;
                }
            });
        }
        const maxLabel = formatMark(marks[marks.length - 1]);
        return marks.map(function(m) {
            const selected = m === closest ? ' selected' : '';
            return `<option value="${formatMark(m)}"${selected}>${formatMark(m)}/${maxLabel}</option>`;
        }).join('');
    }

    /**
     * Read the question's maximum mark out of a grading response.
     *
     * Returns null rather than guessing. Earlier releases defaulted to 3, which built a
     * dropdown for a scale the question does not have and then saved a mark against it.
     *
     * @param {Object} resp Grading response.
     * @return {Number|null} The maximum mark, or null when it is unusable.
     */
    function responseMaxMark(resp) {
        const max = parseFloat(resp && resp.maxmark);
        return isFinite(max) && max > 0 ? roundMark(max) : null;
    }

    /**
     * Work out the mark the grading service suggested.
     *
     * @param {Object} resp Grading response.
     * @param {Number} maxMark The question's maximum mark.
     * @return {Number} Suggested mark, clamped into range.
     */
    function responseSuggestedMark(resp, maxMark) {
        let mark = parseFloat(resp && resp.scaledmark);
        if (!isFinite(mark)) {
            const pct = parseFloat(resp && resp.grade100);
            mark = isFinite(pct) ? (pct / 100) * maxMark : 0;
        }
        return Math.min(Math.max(roundMark(mark), 0), maxMark);
    }

    /* ---------------------------------------------------------
     * Grading criteria
     *
     * The assessment is shown broken down by criterion, with a bar for each, on the
     * assessor's card and in the feedback the learner receives. Until 4.4.0 the plugin
     * sent the rubric to the grading service and got a single number back: the mark and
     * the reasoning were independent, and the criteria the service did report were never
     * displayed anywhere at all.
     *
     * Two shapes are accepted. When the service returns scored criteria the bar shows the
     * proportion awarded. When it returns only the flat met/not-met list, which is what it
     * does today, the bar is full or empty - which is the honest rendering of a binary
     * competency judgement anyway.
     * --------------------------------------------------------- */

    /**
     * Normalise whatever criteria the grading service returned into one shape.
     *
     * @param {Object} resp Grading response.
     * @return {Array} Objects with label, awarded, max and comment.
     */
    function normaliseCriteria(resp) {
        if (!resp) {
            return [];
        }

        // Preferred shape: the service scored each criterion.
        if (Array.isArray(resp.criteria) && resp.criteria.length > 0) {
            return resp.criteria.map(function(c) {
                const max = parseFloat(c.max);
                const awarded = parseFloat(c.awarded);
                return {
                    key: String(c.key || c.label || ''),
                    label: String(c.label || c.key || ''),
                    awarded: isFinite(awarded) ? awarded : 0,
                    max: isFinite(max) && max > 0 ? max : 1,
                    // What the learner did on this criterion, and what would make it
                    // better. Held separately so the second can be withheld when the
                    // criterion is fully met - telling a learner how to improve something
                    // they scored full marks on contradicts the mark.
                    comment: String(c.comment || ''),
                    improve: String(c.improve || '')
                };
            }).filter(c => c.label !== '');
        }

        // Fallback: the flat met/not-met list. Every criterion the service recognises for
        // this question is listed, so the learner can see what is outstanding as well as
        // what is met - a list of only the met ones would not show the gap.
        const met = Array.isArray(resp.criteriamet) ? resp.criteriamet : [];
        const all = Array.isArray(resp.criteriaall) && resp.criteriaall.length > 0 ? resp.criteriaall : met;
        if (all.length === 0) {
            return [];
        }
        return all.map(function(key) {
            return {
                key: key,
                label: criterionLabel(key),
                awarded: met.indexOf(key) !== -1 ? 1 : 0,
                max: 1,
                comment: '',
                improve: ''
            };
        });
    }

    /**
     * Turn a criterion identifier into something a learner can read.
     *
     * @param {String} key e.g. 'hazard_identified'.
     * @return {String} e.g. 'Hazard identified'.
     */
    function criterionLabel(key) {
        const words = String(key).replace(/[_-]+/g, ' ').trim();
        return words.charAt(0).toUpperCase() + words.slice(1);
    }

    /**
     * Render the criteria breakdown as HTML, for the learner.
     *
     * One card per criterion, headed by the criterion's own name from the question's
     * grading criteria. The feedback sits under the criterion it belongs to rather than
     * under three generic headings, so a learner reading "Clarity and structure 7 / 10"
     * sees immediately what cost them the three marks.
     *
     * Every style is inline. This markup is saved to the database as the learner's
     * feedback and is rendered on the quiz review page, where the plugin's stylesheet is
     * not loaded, so a class-based version would appear unstyled to the learner.
     *
     * @param {Array} criteria Normalised criteria.
     * @return {String} HTML, or an empty string when there is nothing to show.
     */
    function renderCriteriaHtml(criteria) {
        if (!Array.isArray(criteria) || criteria.length === 0) {
            return '';
        }

        const cards = criteria.map(function(c) {
            const max = c.max > 0 ? c.max : 1;
            const awarded = Math.min(Math.max(c.awarded, 0), max);
            const pct = Math.round((awarded / max) * 100);
            const scoreLabel = `${formatMark(awarded)} / ${formatMark(max)}`;

            const comment = c.comment
                ? `<div style="margin:9px 0 0;font-size:13px;line-height:1.5;color:#5f6368;">`
                    + escapeHtml(c.comment) + `</div>`
                : '';

            // How to improve is shown only where there are marks still to win. At full
            // marks it contradicts the score, which is the same reasoning behind the
            // site's full-marks policy for the overall remark.
            const improve = (c.improve && awarded < max)
                ? `<div style="margin:7px 0 0;padding:8px 11px;font-size:13px;line-height:1.5;`
                    + `color:#8a4b06;background:#fdf3e7;border-radius:6px;">`
                    + escapeHtml(c.improve) + `</div>`
                : '';

            return `<div style="margin:0 0 10px;padding:13px 16px;background:#ffffff;`
                + `border:1px solid #e3e8ef;border-radius:8px;">`
                + `<div style="display:flex;justify-content:space-between;align-items:baseline;`
                + `gap:12px;margin:0 0 9px;">`
                + `<span style="font-size:14.5px;font-weight:600;color:#202124;">`
                + escapeHtml(c.label) + `</span>`
                + `<span style="font-size:14.5px;font-weight:700;color:#202124;white-space:nowrap;">`
                + escapeHtml(scoreLabel) + `</span>`
                + `</div>`
                + `<div role="img" aria-label="${escapeHtml(c.label)}: ${escapeHtml(scoreLabel)}"`
                + ` style="height:6px;background:#e8eaed;border-radius:999px;overflow:hidden;">`
                + `<div style="height:6px;width:${pct}%;background:#21a44a;border-radius:999px;"></div>`
                + `</div>`
                + comment
                + improve
                + `</div>`;
        }).join('');

        return `<div style="margin:0 0 18px;">`
            + `<div style="margin:0 0 12px;font-size:15px;font-weight:600;color:#1a73e8;">`
            + escapeHtml(strings.criteria_heading || 'AI feedback · marked against your grading criteria')
            + `</div>`
            + cards
            + `</div>`;
    }

    /**
     * Total a set of criteria.
     *
     * @param {Array} criteria Normalised criteria.
     * @return {Object} {awarded, max, pct}.
     */
    function criteriaTotal(criteria) {
        let awarded = 0;
        let max = 0;
        criteria.forEach(function(c) {
            const cmax = c.max > 0 ? c.max : 1;
            awarded += Math.min(Math.max(c.awarded, 0), cmax);
            max += cmax;
        });
        return {
            awarded: roundMark(awarded),
            max: roundMark(max),
            pct: max > 0 ? Math.round((awarded / max) * 100) : 0
        };
    }

    /**
     * Render the criteria for the assessor, with the score on each one editable.
     *
     * The assessor sets every number. That is the point: a mark built from criteria the
     * assessor scored, against criteria the assessor wrote, is defensible in a way that
     * one number from a grading service is not. Where the service supplies per-criterion
     * scores they arrive pre-filled and the assessor confirms or changes them; where it
     * does not, which is today, the assessor fills them in and the bars still work.
     *
     * Classes rather than inline styles here: this only ever renders inside the marking
     * screen, where the plugin's stylesheet is loaded.
     *
     * @param {Array} criteria Normalised criteria.
     * @param {String} id Card identifier.
     * @return {String} HTML, or an empty string when there is nothing to show.
     */
    function renderCriteriaEditor(criteria, id) {
        if (!Array.isArray(criteria) || criteria.length === 0) {
            return '';
        }

        const rows = criteria.map(function(c, i) {
            const max = c.max > 0 ? c.max : 1;
            const awarded = Math.min(Math.max(c.awarded, 0), max);
            const pct = Math.round((awarded / max) * 100);
            const step = Number.isInteger(max) ? 1 : 0.5;

            return `<div class="aigrader-crit">
                <div class="aigrader-crit-head">
                    <label class="aigrader-crit-label" for="crit-${id}-${i}">${escapeHtml(c.label)}</label>
                    <span class="aigrader-crit-score">
                        <input type="number" class="aigrader-crit-input" id="crit-${id}-${i}"
                            data-card="${id}" data-index="${i}"
                            value="${formatMark(awarded)}" min="0" max="${formatMark(max)}"
                            step="${step}" inputmode="decimal">
                        <span class="aigrader-crit-max">/ ${formatMark(max)}</span>
                    </span>
                </div>
                <div class="aigrader-crit-track">
                    <div class="aigrader-crit-fill" id="critbar-${id}-${i}" style="width: ${pct}%"></div>
                </div>
                ${c.comment ? `<p class="aigrader-crit-comment">${escapeHtml(c.comment)}</p>` : ''}
                ${c.improve && awarded < max
                    ? `<p class="aigrader-crit-improve" id="critimp-${id}-${i}">${escapeHtml(c.improve)}</p>`
                    : (c.improve
                        ? `<p class="aigrader-crit-improve" id="critimp-${id}-${i}" hidden>`
                            + `${escapeHtml(c.improve)}</p>`
                        : '')}
            </div>`;
        }).join('');

        const t = criteriaTotal(criteria);
        return `<div class="aigrader-crit-block">
            <div class="aigrader-crit-heading">${escapeHtml(strings.criteria_heading)}</div>
            ${rows}
            <div class="aigrader-crit-total">
                <span class="aigrader-crit-total-label">${escapeHtml(strings.criteria_total)}</span>
                <span class="aigrader-crit-total-value" id="crittotal-${id}">
                    ${formatMark(t.awarded)} / ${formatMark(t.max)}</span>
            </div>
        </div>`;
    }

    /**
     * Read a card's criteria back out of the hidden field.
     *
     * @param {String} id Card identifier.
     * @return {Array} Normalised criteria, or an empty array.
     */
    function readCriteria(id) {
        const raw = $('#criteriadata-' + id).val();
        if (!raw) {
            return [];
        }
        try {
            const parsed = JSON.parse(raw);
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            return [];
        }
    }

    /**
     * Apply the assessor's criterion scores to the card.
     *
     * Recomputes the total, moves the bar, picks the nearest awardable mark on the
     * question's own scale, updates the headline figure, and regenerates the HTML the
     * learner will receive. The mark becomes the sum of the criteria rather than a
     * separate number that happens to sit beside them.
     *
     * @param {String} id Card identifier.
     */
    function applyCriteriaScores(id) {
        const criteria = readCriteria(id);
        if (criteria.length === 0) {
            return;
        }

        const maxMark = parseFloat($('#maxmark-' + id).val());
        const total = criteriaTotal(criteria);

        // Bars, and the improve note, which is hidden once a criterion is fully met.
        criteria.forEach(function(c, i) {
            const cmax = c.max > 0 ? c.max : 1;
            const awarded = Math.min(Math.max(c.awarded, 0), cmax);
            $('#critbar-' + id + '-' + i).css('width', Math.round((awarded / cmax) * 100) + '%');
            const $imp = $('#critimp-' + id + '-' + i);
            if ($imp.length) {
                $imp.prop('hidden', awarded >= cmax);
            }
        });

        $('#crittotal-' + id).text(`${formatMark(total.awarded)} / ${formatMark(total.max)}`);

        // The criteria are scored on their own scale; the question has its own maximum.
        // Scale between them rather than assuming they agree.
        if (isFinite(maxMark) && maxMark > 0 && total.max > 0) {
            const wanted = (total.awarded / total.max) * maxMark;
            const scale = buildMarkScale(maxMark);
            // Nearest awardable mark, and on an exact tie the LOWER one. A criteria total
            // that lands halfway between two awardable marks should not quietly round in
            // the learner's favour; the assessor raises it if they judge it should be
            // raised, and that judgement is then theirs and is recorded as theirs.
            let closest = scale.length ? scale[0] : 0;
            scale.forEach(function(m) {
                if (Math.abs(m - wanted) < Math.abs(closest - wanted)) {
                    closest = m;
                }
            });
            $('#grade-select-' + id).val(formatMark(closest));
            $('#suggested-' + id).text(
                strings.suggested_grade
                    .replace('{$a->mark}', String(Math.round((closest / maxMark) * 100)))
                    .replace('{$a->maxmark}', '100')
            );
        }

        // Regenerate what the learner will receive, then re-run the feedback pipeline so
        // the preview and the full-marks policy both see the new mark.
        $('#criteria-' + id).val(renderCriteriaHtml(criteria));
        refreshFeedbackForCard(id);
    }

    /**
     * Apply the site's full-marks policy to feedback text.
     *
     * Competency-based assessment is a binary judgement. A learner who has met every
     * criterion is competent, and telling them what to improve or what to do differently
     * next time implies a gap the grade says is not there. By default, then, a learner on
     * full marks keeps the strengths and any notices and nothing else. Sites that want the
     * older behaviour, or everything regardless, choose that in the plugin settings.
     *
     * This runs as the feedback is loaded into the card, before the marker sees it, so the
     * editor holds exactly what the learner will receive and the two can never disagree.
     * The marker remains free to write whatever they like afterwards.
     *
     * Returns what was removed as well as what was kept, so the assessor can be shown the
     * withheld text rather than having it silently discarded, and so a policy that
     * recognised no sections at all can say so instead of quietly delivering the opposite
     * of what the site configured.
     *
     * @param {String} text Raw feedback text.
     * @param {String} mode 'strengths', 'nosuggestions' or 'all'.
     * @return {Object} {text, removed, recognised}.
     */
    function applyFullMarksPolicy(text, mode) {
        if (typeof text !== 'string' || text === '' || mode === 'all') {
            return {text: typeof text === 'string' ? text : '', removed: [], recognised: true};
        }

        // 'nosuggestions' keeps the improvements section and drops only the suggestions.
        const dropTypes = mode === 'nosuggestions' ? ['info'] : ['warning', 'info'];

        const kept = [];
        const removed = [];
        let dropping = false;
        let recognised = false;

        for (const rawline of text.split('\n')) {
            const type = detectSectionType(rawline.trim());
            if (type !== null) {
                recognised = true;
                dropping = dropTypes.indexOf(type) !== -1;
            }
            if (dropping) {
                removed.push(rawline);
            } else {
                kept.push(rawline);
            }
        }

        return {text: kept.join('\n').trim(), removed: removed, recognised: recognised};
    }

    /**
     * Re-derive a card's feedback from the unfiltered original.
     *
     * Called when the card is built and again whenever the assessor changes the mark. The
     * policy was applied once at render until 4.4.0, which desynchronised the moment the
     * mark moved: an assessor lowering a suggested full mark delivered a reduced mark with
     * the gap analysis already deleted and no way to get it back, and one raising a mark to
     * full delivered "what to improve next time" to a learner judged competent.
     *
     * @param {String} id Card identifier.
     */
    function refreshFeedbackForCard(id) {
        const $source = $('#feedback-source-' + id);
        if ($source.length === 0) {
            return;
        }

        const source = $source.val() || '';
        const maxMark = parseFloat($('#maxmark-' + id).val());
        const mark = parseFloat($('#grade-select-' + id).val());
        const mode = config.fullMarksFeedback || 'strengths';
        const atFullMarks = isFinite(mark) && isFinite(maxMark) && maxMark > 0
            && mark >= maxMark - MARK_EPSILON;

        let result = {text: source, removed: [], recognised: true};
        if (atFullMarks) {
            result = applyFullMarksPolicy(source, mode);
        }

        $('#feedback-edit-' + id).val(result.text);
        $('#feedback-preview-' + id).html(
            ($('#criteria-' + id).val() || '') + formatFeedbackHtml(result.text)
        );

        // Tell the assessor what the site policy did. Text removed by policy is never
        // delivered to the learner, but it is the clearest statement of why the AI judged
        // as it did, so it stays available to the person making the decision.
        const $note = $('#policy-note-' + id);
        if ($note.length === 0) {
            return;
        }
        if (atFullMarks && mode !== 'all' && !result.recognised) {
            $note.html(
                `<div class="aigrader-policy-note aigrader-policy-warn">
                    ${escapeHtml(strings.policy_not_applied)}</div>`
            );
        } else if (result.removed.length > 0) {
            const withheld = result.removed.join('\n').trim();
            $note.html(
                `<details class="aigrader-policy-note">
                    <summary>${escapeHtml(strings.policy_withheld)}</summary>
                    <pre class="aigrader-policy-withheld">${escapeHtml(withheld)}</pre>
                </details>`
            );
        } else {
            $note.empty();
        }
    }

    /**
     * Render the AI's feedback as the learner will see it.
     *
     * The markup carries inline styles because this HTML is stored on the attempt and
     * shown again on Moodle's review page, which does not load this plugin's stylesheet.
     *
     * Format feedback into styled cards - with inline styles
     * Uses inline styles so it looks good in Moodle's review attempt view
     * Three sections: success (green), warning (amber), info (blue)
     *
     * @param {String} text The feedback as the grading service returned it.
     * @return {String} The feedback as HTML.
     */
    function formatFeedbackHtml(text) {
        // Defensive: ensure text is a string
        if (typeof text !== 'string') {
            log('formatFeedbackHtml received non-string:', typeof text, text);
            if (text === null || text === undefined) {
                return '<div style="font-family:Inter,system-ui,-apple-system,sans-serif;font-size:14px;' +
                    'color:#626c7a;font-style:italic;">' + escapeHtml(strings.feedback_none) + '</div>';
            }
            // Try to stringify objects
            try {
                text = JSON.stringify(text, null, 2);
            } catch (e) {
                text = String(text);
            }
        }

        // Parse text into sections. Any line that appears before the first recognised
        // heading is kept as preamble rather than discarded, so notices prepended by the
        // server (auto-lock, human review) and anything a marker types above the first
        // heading still reach the student.
        const sections = [];
        const preamble = [];
        let currentSection = null;

        // Comprehensive regex to strip all bullet prefixes consistently
        // Covers: bullets (*****), squares (**), triangles ( >  > ), dashes (--- -  - ), middle dots (***), arrows ( ->  =>  > )
        // Also covers the coloured circle emoji - green, orange, blue, purple, yellow,
        // red, black and white - which the model often returns as bullet markers.
        // Applied to handle double bullets like "* *" and numbered lists like "1." or "1)" or "1:"
        // Uses + quantifier to strip consecutive bullet chars, and repeats if needed
        // eslint-disable-next-line max-len -- one regex literal; wrapping it would change the pattern.
        const STRIP = /^(?:[******* >  > >>**--- -  - *** ->  =>  > \u2022\u2023\u2043\u25CF\u25CB\u25E6\u25AA\u25AB\u25B8\u25B9\u25BA\u25BB\u25C6\u25C7\u2010\u2011\u2012\u2013\u2014\u00B7\u2219\u22C5\u2192\u21D2\u27A4\u{1F7E0}\u{1F7E1}\u{1F7E2}\u{1F7E3}\u{1F7E4}\u{1F534}\u{1F535}\u{1F7E5}\u{1F7E6}\u{1F7E7}\u{1F7E8}\u{1F7E9}\u{1F7EA}\u{1F7EB}\u26AB\u26AA\u2B24]+\s*|\d+[.):]\s*|[-*+]\s+)/u;

        const lines = text.split('\n');

        for (let i = 0; i < lines.length; i++) {
            const line = lines[i].trim();
            if (!line) {
                continue;
            }

            const headingType = detectSectionType(line);
            if (headingType === 'success') {
                if (currentSection) {
                    sections.push(currentSection);
                }
                const title = line.replace(/^(\[thumbs-up\]|\s)+/, '').trim() || strings.feedback_strengths;
                currentSection = { type: 'success', title: title, icon: '[thumbs-up]', items: [] };
            } else if (headingType === 'warning') {
                if (currentSection) {
                    sections.push(currentSection);
                }
                const title = line.replace(/^(\[chart-down\]|\s)+/, '').trim() || strings.feedback_improvements;
                currentSection = { type: 'warning', title: title, icon: '[chart-down]', items: [] };
            } else if (headingType === 'info') {
                if (currentSection) {
                    sections.push(currentSection);
                }
                const title = line.replace(/^(\[tip\]|\u2753|\s)+/u, '').trim() || strings.feedback_suggestions;
                currentSection = { type: 'info', title: title, icon: '[tip]', items: [] };
            } else {
                // Add as bullet item - strip bullets/emoji repeatedly for double bullets like "* * text"
                let cleanText = line;
                let prevLen = 0;
                while (cleanText.length !== prevLen && STRIP.test(cleanText)) {
                    prevLen = cleanText.length;
                    cleanText = cleanText.replace(STRIP, '').trim();
                }
                if (cleanText) {
                    if (currentSection) {
                        currentSection.items.push(cleanText);
                    } else {
                        // No heading has been seen yet, so this is preamble. Keeping it means
                        // nothing the marker wrote is silently dropped on its way to the student.
                        preamble.push(cleanText);
                    }
                }
            }
        }

        // Push last section
        if (currentSection) {
            sections.push(currentSection);
        }

        // SVG icons for each section type (inline SVG so they work everywhere)
        const icons = {
            success: '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"'
                + ' fill="none" stroke="currentColor" stroke-width="2.5"'
                + ' stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;">'
                + '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>'
                + '<polyline points="22 4 12 14.01 9 11.01"/></svg>',
            warning: '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"'
                + ' fill="none" stroke="currentColor" stroke-width="2.5"'
                + ' stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;">'
                + '<circle cx="12" cy="12" r="10"/>'
                + '<line x1="12" y1="8" x2="12" y2="12"/>'
                + '<line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
            info: '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"'
                + ' fill="none" stroke="currentColor" stroke-width="2.5"'
                + ' stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;">'
                + '<line x1="12" y1="16" x2="12" y2="12"/>'
                + '<line x1="12" y1="8" x2="12.01" y2="8"/>'
                + '<circle cx="12" cy="12" r="10"/></svg>'
        };

        // Bullet icons - small checkmark for success, small dash for warning/info
        // Palette from the LMS Labs design system. Values are precomputed rather than
        // written with color-mix(), because this markup is saved to the database and
        // rendered later on Moodle's review page, where the plugin's stylesheet is not
        // guaranteed to load. Everything that matters has to survive as inline style.
        const styles = {
            success: {
                cardClass: 'feedback-card feedback-card-success',
                bg: '#f0faf4',
                border: '1px solid #b4e2c5',
                chipBg: '#def2e6',
                accent: '#16a34a',
                titleColor: '#166534'
            },
            warning: {
                cardClass: 'feedback-card feedback-card-warning',
                bg: '#fdf7f0',
                border: '1px solid #f3d4af',
                chipBg: '#faecdc',
                accent: '#d97706',
                titleColor: '#92400e'
            },
            info: {
                cardClass: 'feedback-card feedback-card-info',
                bg: '#f2f6fe',
                border: '1px solid #b9cdf9',
                chipBg: '#e0e9fc',
                accent: '#2563eb',
                titleColor: '#1e40af'
            }
        };

        const FONT = "Inter, system-ui, -apple-system, 'Segoe UI', sans-serif";
        const TEXT = '#1d2129';

        let html = '<div class="aigrader-feedback" style="font-family:' + FONT +
            ';line-height:1.6;color:' + TEXT + ';">';

        // Anything written above the first heading - including the competency and
        // assessor-review notices the service prepends - is shown as a notice card rather
        // than loose paragraphs, so it reads as deliberate instead of stray text.
        if (preamble.length > 0) {
            html += '<div class="feedback-notice" style="background:#f6f7f8;border:1px solid #dee2e8;' +
                'border-left:3px solid #64748b;border-radius:10px;padding:12px 16px;margin:0 0 12px 0;">';
            for (let i = 0; i < preamble.length; i++) {
                html += '<p style="margin:' + (i === 0 ? '0' : '8px 0 0') + ';font-size:13px;' +
                    'line-height:1.6;color:' + TEXT + ';">' + escapeHtml(preamble[i]) + '</p>';
            }
            html += '</div>';
        }

        for (const section of sections) {
            const s = styles[section.type] || styles.info;
            const icon = icons[section.type] || icons.info;

            html += '<div class="' + s.cardClass + '" style="background:' + s.bg + ';border:' + s.border +
                ';border-radius:12px;padding:16px 18px;margin:0 0 12px 0;">';

            // Title row: a tinted chip holding the icon, then the heading.
            html += '<div class="feedback-card-title" style="display:flex;align-items:center;gap:10px;' +
                'margin:0 0 10px 0;">';
            html += '<span style="display:inline-flex;align-items:center;justify-content:center;' +
                'width:24px;height:24px;border-radius:999px;background:' + s.chipBg + ';color:' +
                s.accent + ';flex:0 0 auto;">' + icon + '</span>';
            html += '<span style="font-size:14px;font-weight:700;letter-spacing:-0.01em;color:' +
                s.titleColor + ';">' + escapeHtml(section.title) + '</span>';
            html += '</div>';

            if (section.items.length > 0) {
                html += '<div class="feedback-card-list" style="margin:0;padding:0;list-style:none;">';
                for (const item of section.items) {
                    html += '<div class="feedback-card-item" style="display:flex;align-items:flex-start;' +
                        'gap:10px;margin:0 0 6px 0;font-size:14px;line-height:1.65;color:' + TEXT + ';">';
                    // A small solid dot reads more calmly than an outlined icon per bullet.
                    html += '<span class="feedback-bullet" style="flex:0 0 auto;width:5px;height:5px;' +
                        'margin-top:8px;border-radius:999px;background:' + s.accent + ';"></span>';
                    html += '<span>' + escapeHtml(item) + '</span>';
                    html += '</div>';
                }
                html += '</div>';
            }

            html += '</div>';
        }

        html += '</div>';

        // If no sections found, show as formatted plain text with paragraph breaks
        if (sections.length === 0) {
            const paragraphs = text.split('\n').filter(p => p.trim());
            html = '<div class="feedback-plain" style="font-family:' + FONT +
                ';font-size:14px;line-height:1.65;color:' + TEXT + ';">';
            for (const p of paragraphs) {
                html += '<p style="margin:0 0 10px 0;">' + escapeHtml(p.trim()) + '</p>';
            }
            html += '</div>';
        }

        return html;
    }

    /**
     * Escape a value for insertion into markup, in text or in an attribute.
     *
     * The previous version assigned to textContent and read back innerHTML, which escapes
     * the angle brackets and the ampersand but leaves both quote characters alone. That is
     * safe between tags and unsafe inside an attribute, and this function was being used
     * in both places, so a value carrying a quote could close the attribute it sat in.
     *
     * @param {*} text The value to escape.
     * @return {String} The escaped value, '' for null or undefined.
     */
    function escapeHtml(text) {
        if (text === null || text === undefined) {
            return '';
        }
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    /* ---------------------------------------------------------
     * Grade ONE essay - shows editable feedback for teacher approval
     * --------------------------------------------------------- */
    /**
     * Grade one response
     *
     * One implementation serves both the single AI Grade button and Grade All. They used
     * to be two near-identical 200-line copies, which is how a fix to one of them (the
     * expand-on-grade call) reached only one screen. The single difference the callers
     * actually need is whether a failure is announced: on its own a failure is an alert,
     * and inside a bulk run it is not, so the queue keeps moving.
     *
     * @param {Number} qubaid The question usage this response belongs to.
     * @param {Number} slot The slot within that usage.
     * @param {jQuery} $btn The AI Grade button that was pressed.
     * @param {Function|null} callback Called when this response is finished, in a bulk run.
     * @return {void}
     */
    function gradeRequest(qubaid, slot, $btn, callback) {
        const id = `${qubaid}-${slot}`;

        $btn.prop('disabled', true)
            .html(`<span class="aigrader-spinner"></span> ${strings.grading}`);
        setRowStatus(id, 'grading');

        $.post(config.ajaxurl, {
            action: 'suggest',
            sesskey: config.sesskey,
            cmid: config.cmid,
            qubaid: qubaid,
            slot: slot,
            language: config.language || ''
        }, null, 'json')
        .done(resp => {
            log('GRADE response:', resp);

            if (!resp.ok) {
                $btn.prop('disabled', false).text(strings.ai_grade);
                setRowStatus(id, 'pending');
                if (callback) {
                    // Grading the whole queue: stay quiet and move to the next response,
                    // rather than stacking one alert per failure on top of the assessor.
                    callback();
                } else {
                    showAlert('error', strings.error_grading, resp.message);
                }
                return;
            }

            // Build the grade dropdown from the question's own mark scale. Option values are
            // absolute marks, so what is saved is exactly what the assessor selected.
            const maxMark = responseMaxMark(resp);
            if (maxMark === null) {
                $btn.prop('disabled', false).text(strings.ai_grade);
                showAlert('error', strings.error_grading, strings.error_nomaxmark);
                if (callback) {
                    callback();
                }
                return;
            }
            const suggestedMark = responseSuggestedMark(resp, maxMark);
            const gradeOptions = buildMarkOptions(maxMark, suggestedMark);
            // The headline figure on the card, reported out of 100 the way the reference
            // screens show it, independently of the question's own mark scale.
            const suggestedLabel = strings.suggested_grade
                .replace('{$a->mark}', formatMark(Math.round((suggestedMark / maxMark) * 100)))
                .replace('{$a->maxmark}', '100');

            // Format feedback into cards for the preview.
            // Handle case where OpenAI returns feedback as object instead of string
            let feedbackRaw = resp.feedback || '';
            if (typeof feedbackRaw === 'object') {
                // Convert object to formatted string
                if (feedbackRaw.sections) {
                    feedbackRaw = Object.entries(feedbackRaw.sections || feedbackRaw)
                        .map(([key, val]) => `${key}:\n${Array.isArray(val) ? val.join('\n') : val}`)
                        .join('\n\n');
                } else {
                    feedbackRaw = Object.entries(feedbackRaw)
                        .map(([key, val]) => {
                            const header = key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
                            const content = Array.isArray(val) ? val.map(v => `- ${v}`).join('\n') : val;
                            return `${header}:\n${content}`;
                        })
                        .join('\n\n');
                }
            }
            // The criteria breakdown the learner will see, and the unfiltered feedback the
            // card re-derives from whenever the assessor changes the mark. The preview and
            // the textarea are filled by refreshFeedbackForCard() once the card is in the
            // DOM, so there is exactly one code path that decides what the learner receives.
            // The criteria are held as data on the card. The editor is rendered from it,
            // and the learner-facing HTML is regenerated from it whenever a score changes,
            // so the two can never drift apart.
            const criteriaData = normaliseCriteria(resp);
            const criteriaHtml = renderCriteriaHtml(criteriaData);
            const criteriaEditor = renderCriteriaEditor(criteriaData, id);
            const feedbackPreview = '';

            log('Attempt context:', {
                attemptNum: resp.attemptnum,
                maxAttempts: resp.maxattempts,
                previousAttempt: resp.previousattempt
            });

            // Build the attempt context badge.
            const attemptNum = resp.attemptnum || 1;
            const maxAttempts = resp.maxattempts || 0;
            const attemptBadge = maxAttempts > 0
                ? `<span class="aigrader-attempt-badge ag-badge ag-badge-info">${escapeHtml(
                    strings.attemptnumberofmax.replace('{$a->num}', attemptNum).replace('{$a->total}', maxAttempts))}</span>`
                : `<span class="aigrader-attempt-badge ag-badge ag-badge-info">${escapeHtml(
                    strings.attemptnumber.replace('{$a}', attemptNum))}</span>`;

            // Build previous attempt section if available
            let previousAttemptHtml = '';
            if (resp.previousattempt && (resp.previousattempt.feedback || resp.previousattempt.grade !== null)) {
                const prevGrade = resp.previousattempt.grade;
                const prevFeedback = resp.previousattempt.feedback || strings.feedback_notrecorded;
                const prevGradeDisplay = prevGrade !== null ? `${prevGrade}%` : strings.notgraded;
                let prevGradeBadgeClass = 'ag-badge-error';
                if (prevGrade >= 100) {
                    prevGradeBadgeClass = 'ag-badge-success';
                } else if (prevGrade >= 50) {
                    prevGradeBadgeClass = 'ag-badge-warning';
                }
                previousAttemptHtml = `
                    <div class="aigrader-previous-attempt">
                        <button type="button" class="aigrader-previous-toggle" data-expanded="false">
                            <svg class="aigrader-chevron ag-icon-xs" xmlns="http://www.w3.org/2000/svg"
                                 viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="9 18 15 12 9 6"/>
                            </svg>
                            <span>${escapeHtml(strings.previousattempt)}</span>
                            <span class="aigrader-previous-grade ag-badge ${prevGradeBadgeClass}">${prevGradeDisplay}</span>
                        </button>
                        <div class="aigrader-previous-content" style="display: none;">
                            <div class="aigrader-previous-feedback">${escapeHtml(prevFeedback)}</div>
                        </div>
                    </div>`;
            }

            // Hide the initial actions row and show the feedback area
            $('#initial-actions-' + id).hide();

            // Show full-width feedback with beautiful formatted preview
            $('#feedback-' + id)
                .show()
                .css('display', 'block')
                .html(`
                    <div class="aigrader-feedback-card aigrader-pending-approval">
                        <div class="aigrader-feedback-topline">${attemptBadge}</div>
                        ${previousAttemptHtml}
                        <input type="hidden" id="feedback-source-${id}" value="${escapeHtml(feedbackRaw)}">
                        <input type="hidden" id="criteria-${id}" value="${escapeHtml(criteriaHtml)}">
                        <input type="hidden" id="criteriadata-${id}" value="${escapeHtml(JSON.stringify(criteriaData))}">
                        <input type="hidden" id="maxmark-${id}" value="${maxMark}">
                        <div id="criteria-editor-${id}">${criteriaEditor}</div>
                        <div class="aigrader-feedback-preview" id="feedback-preview-${id}">${feedbackPreview}</div>
                        <div id="policy-note-${id}"></div>
                        <div class="aigrader-feedback-barline">
                            <span class="aigrader-feedback-label">${escapeHtml(strings.feedback_to_learner)}</span>
                            <span class="aigrader-feedback-barline-right">
                                ${resp.languagelabel
                                    ? `<span class="aigrader-lang-pill">`
                                        + `${escapeHtml(resp.languagelabel)}</span>`
                                    : ''}
                                <span class="aigrader-saved-pill" id="saved-pill-${id}"
                                      hidden>${escapeHtml(strings.changes_saved)}</span>
                                <span class="aigrader-grade-select">
                                    <label for="grade-select-${id}">${strings.grade_label}</label>
                                    <select class="aigrader-grade-dropdown" id="grade-select-${id}">
                                        ${gradeOptions}
                                    </select>
                                </span>
                                <button type="button" data-id="${id}"
                                        class="aigrader-btn aigrader-btn-sm aigrader-btn-outline aigrader-toggle-edit-btn">
                                    <svg class="ag-icon-xs" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                         fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                    </svg>
                                    ${escapeHtml(strings.edit)}
                                </button>
                                <span class="aigrader-edit-actions" id="edit-actions-${id}" hidden>
                                    <button type="button" data-id="${id}"
                                            class="aigrader-btn aigrader-btn-sm aigrader-btn-primary aigrader-save-edit-btn">
                                        <svg class="ag-icon-xs" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                             fill="none" stroke="currentColor" stroke-width="2">
                                            <polyline points="20 6 9 17 4 12"/>
                                        </svg>
                                        ${escapeHtml(strings.save_changes)}
                                    </button>
                                    <button type="button" data-id="${id}"
                                            class="aigrader-btn aigrader-btn-sm aigrader-btn-outline aigrader-cancel-edit-btn">
                                        ${escapeHtml(strings.cancel)}
                                    </button>
                                </span>
                            </span>
                        </div>
                        <textarea class="aigrader-feedback-edit" id="feedback-edit-${id}" readonly></textarea>
                        <div class="aigrader-approval-actions">
                            <span class="aigrader-review-hint" id="review-hint-${id}"
                                  hidden>${escapeHtml(strings.read_answer_first)}</span>
                            <button type="button"
                                    class="aigrader-btn aigrader-btn-primary aigrader-approve-btn
                                           ag-btn-base ag-btn-primary"
                                    data-qubaid="${qubaid}" data-slot="${slot}" data-id="${id}">
                                <svg class="ag-icon-sm ag-icon-fixed" xmlns="http://www.w3.org/2000/svg"
                                     width="16" height="16" fill="none" stroke="currentColor"
                                     stroke-width="2" viewBox="0 0 24 24">
                                    <path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3z
                                             M7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/>
                                </svg>
                                ${strings.approve_save}
                            </button>
                        </div>
                    </div>`
                );

            // FIX: Set textarea value directly via DOM .value (bypasses jQuery .html()
            // HTML-entity parsing). Without this, AI feedback containing &, <, >, or the
            // string </textarea> gets mangled by the browser's HTML parser when the card
            // is injected via .html()  -  causing .val() to return corrupt/empty text at
            // approve time, silently saving wrong or empty feedback to Moodle.
            // One path fills the textarea and the preview, so they cannot disagree.
            refreshFeedbackForCard(id);
            setRowStatus(id, 'graded');
            setRowExpanded(id, true);

            // The headline figure belongs in the card header, top right, per the reference.
            $('#suggested-' + id)
                .text(suggestedLabel)
                .addClass('aigrader-suggested-grade');

            // Update grade label to show pending
            $('#grade-' + id)
                .text(resp.grade)
                .addClass('aigrader-grade-pending');

            // Update credits
            if (resp.credits !== undefined) {
                updateCredits(resp.credits);
            }

            // Hide the AI Grade button: it is replaced by the Approve flow, and the
            // essay count is taken from the buttons still showing.
            $btn.hide().css('display', 'none').prop('disabled', true);

            // Start review countdown on this approve button if min_review_time is set
            var $approveBtn = $(`.aigrader-approve-btn[data-id="${id}"]`);
            if ($approveBtn.length) {
                startReviewCountdown($approveBtn);
            }

            if (callback) {
                callback();
            }
        })
        .fail((xhr) => {
            log('GRADE ajax failure:', xhr.responseText);
            $btn.prop('disabled', false).text(strings.ai_grade);
            if (callback) {
                callback();
            } else {
                showAlert('error', strings.error, strings.error_connection);
            }
        });
    }

    /**
     * Grade one response, on its own.
     *
     * @param {Number} qubaid The question usage this response belongs to.
     * @param {Number} slot The slot within that usage.
     * @param {jQuery} $btn The AI Grade button that was pressed.
     * @return {void}
     */
    function gradeOne(qubaid, slot, $btn) {
        gradeRequest(qubaid, slot, $btn, null);
    }

    /**
     * Grade one response as part of a bulk run.
     *
     * @param {Number} qubaid The question usage this response belongs to.
     * @param {Number} slot The slot within that usage.
     * @param {jQuery} $btn The AI Grade button for that row.
     * @param {Function} callback Called when this response is finished.
     * @return {void}
     */
    function gradeOneAsync(qubaid, slot, $btn, callback) {
        gradeRequest(qubaid, slot, $btn, callback);
    }


    /**
     * Lock an approve button for the configured minimum review time.
     *
     * The countdown is a prompt, not the control: the server checks the elapsed time
     * again before it accepts the decision.
     *
     * Minimum review time countdown on approve buttons
     * Locks the button with a visible countdown + reminder message.
     *
     * @param {jQuery} $btn The approve button to lock.
     * @return {void}
     */
    function startReviewCountdown($btn) {
        var seconds = parseInt(config.minReviewTime, 10) || 0;
        if (seconds <= 0) {
            return;
        }

        var id = $btn.data('id');
        var originalHtml = $btn.html();
        $btn.data('ag-original-html', originalHtml);
        $btn.prop('disabled', true).addClass('aigrader-approve-locked');
        $('#review-hint-' + id).prop('hidden', false);

        var label = String(strings.approve_save);
        var remaining = seconds;

        /**
         * Format a number of seconds as m:ss for the countdown label.
         *
         * @param {Number} total Seconds remaining.
         * @return {String} The formatted time.
         */
        function clockFace(total) {
            var m = Math.floor(total / 60);
            var sec = total % 60;
            return m + ':' + (sec < 10 ? '0' : '') + sec;
        }

        /**
         * Count one second off the review timer and unlock the button at zero.
         * @return {void}
         */
        function tick() {
            if (remaining <= 0) {
                $btn.prop('disabled', false).removeClass('aigrader-approve-locked').html(originalHtml);
                $('#review-hint-' + id).prop('hidden', true);
                return;
            }
            // The reference shows the button keeping its own label with the remaining time
            // appended, behind a padlock, rather than being replaced by a countdown.
            $btn.html(
                '<svg class="ag-icon-sm ag-icon-fixed" xmlns="http://www.w3.org/2000/svg" width="16"'
                + ' height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">'
                + '<rect x="3" y="11" width="18" height="11" rx="2"/>'
                + '<path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> '
                + escapeHtml(label) + ' \u00b7 ' + clockFace(remaining)
            );
            remaining--;
            // The handle is stored so the chain can be stopped when the card is replaced.
            // Without it the timer kept ticking against a detached node for the rest of the
            // page's life, once per card graded.
            $btn.data('ag-countdown-timer', setTimeout(tick, 1000));
        }

        cancelReviewCountdown($btn);
        tick();
    }

    /**
     * Stop a card's review countdown.
     *
     * @param {Object} $btn The approve button the countdown is attached to.
     */
    function cancelReviewCountdown($btn) {
        const handle = $btn.data('ag-countdown-timer');
        if (handle) {
            clearTimeout(handle);
            $btn.removeData('ag-countdown-timer');
        }
    }

    /**
     * Start the review countdown on the next approve button to appear.
     *
     * Grading replaces a row's buttons, so the countdown has to be attached after the
     * new approve button exists rather than when the page loads.
     * @return {void}
     */
    function lockNextApproveButton() {
        var seconds = parseInt(config.minReviewTime, 10) || 0;
        if (seconds <= 0) {
            return;
        }

        var $next = $('.aigrader-approve-btn:not(:disabled):not(.aigrader-approve-locked)').first();
        if ($next.length) {
            startReviewCountdown($next);
        }
    }

    /**
     * Set a queue row's status cell.
     *
     * @param {String} id Row identifier.
     * @param {String} state 'pending', 'grading', 'graded' or 'approved'.
     */
    /**
     * Expand or collapse a response's detail row.
     *
     * The queue is a table of responses; the marking card lives in a row beneath each one
     * and is revealed on demand. Grading a row opens it automatically, so the assessor
     * never has to hunt for the card they just asked for.
     *
     * This lived inside registerHandlers() until 4.5.1, where the click handlers could
     * reach it but grading could not: the call that opens a row the moment its mark comes
     * back threw a ReferenceError, which also abandoned the rest of that success handler.
     *
     * @param {String} id The row identifier, qubaid-slot.
     * @param {Boolean} expanded True to reveal the marking card, false to close it.
     * @return {void}
     */
    function setRowExpanded(id, expanded) {
        $('#detail-' + id).prop('hidden', !expanded);
        $(`.aigrader-expand-btn[data-rowid="${id}"]`)
            .attr('aria-expanded', expanded ? 'true' : 'false')
            .toggleClass('is-open', expanded);
    }

    /**
     * Set the status pill on a response's row.
     *
     * @param {String} id The row identifier, qubaid-slot.
     * @param {String} state One of pending, grading, graded or approved.
     * @return {void}
     */
    function setRowStatus(id, state) {
        const labels = {
            pending: strings.status_pending,
            grading: strings.status_grading,
            graded: strings.status_graded,
            approved: strings.status_approved
        };
        $('#status-' + id)
            .removeClass('aigrader-status-pending aigrader-status-grading '
                + 'aigrader-status-graded aigrader-status-approved')
            .addClass('aigrader-status-' + state)
            .text(labels[state] || labels.pending);
    }

    /**
     * Send the assessor's decision to the server and save the mark.
     *
     * The mark is read from the dropdown as an absolute value and sent unchanged; the
     * criteria scores and the edited feedback go with it, so the record stores what the
     * assessor actually approved.
     *
     * Approve and save grade to Moodle
     *
     * @param {Number} qubaid The question usage this response belongs to.
     * @param {Number} slot The slot within that usage.
     * @param {String} id The row identifier, qubaid-slot.
     * @return {void}
     */
    function approveGrade(qubaid, slot, id) {
        // The dropdown's value is an absolute mark. It is sent as-is and the server stores
        // it unchanged; nothing is routed through a percentage on the way.
        const $select = $('#grade-select-' + id);
        const mark = parseFloat($select.val());
        if (!isFinite(mark)) {
            showAlert('error', strings.error, strings.error_nomarkselected);
            return;
        }
        const feedbackRaw = $('#feedback-edit-' + id).val();
        // The criteria breakdown is saved with the feedback so the learner sees it on the
        // quiz review page, where the plugin's stylesheet is not loaded - which is why that
        // markup carries inline styles.
        const criteriaHtml = $('#criteria-' + id).val() || '';
        const feedbackHtml = criteriaHtml + formatFeedbackHtml(feedbackRaw);
        const $btn = $(`.aigrader-approve-btn[data-id="${id}"]`);


        $btn.prop('disabled', true)
            .html(`<span class="aigrader-spinner"></span> ${strings.saving}`);

        // Use FormData to handle special characters (emojis) properly
        const formData = new FormData();
        formData.append('action', 'approve');
        formData.append('sesskey', config.sesskey);
        formData.append('cmid', config.cmid);
        formData.append('qubaid', qubaid);
        formData.append('slot', slot);
        formData.append('mark', mark);
        formData.append('feedbackhtml', feedbackHtml);
        // Recorded on the decision so the feedback the learner received can be explained.
        formData.append('policymode', config.fullMarksFeedback || 'strengths');
        // The breakdown behind the mark, recorded with the decision.
        const criteriaJson = $('#criteriadata-' + id).val();
        if (criteriaJson) {
            formData.append('criteria', criteriaJson);
        }
        // Present only once the assessor has acknowledged a flagged attempt. The server
        // decides whether an acknowledgement was required; sending one never implies it was.
        const acknowledgement = $(`#aigrader-ack-${id} textarea`).val();
        if (acknowledgement) {
            formData.append('acknowledgement', acknowledgement);
        }

        $.ajax({
            url: config.ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json'
        })
        .done(resp => {
            log('APPROVE response:', resp);

            if (!resp.ok) {
                $btn.prop('disabled', false).html(strings.approve_save);

                // The attempt is flagged for assessor review. Ask for the reason in the card
                // rather than in a browser dialog, which would block every later tool call,
                // and resend only once the assessor has written something.
                if (resp.needsacknowledgement && $(`#aigrader-ack-${id}`).length === 0) {
                    $btn.before(
                        `<div id="aigrader-ack-${id}" class="aigrader-ack">` +
                        `<label for="aigrader-ack-input-${id}">${escapeHtml(resp.message)}</label>` +
                        `<textarea id="aigrader-ack-input-${id}" class="aigrader-ack-input" rows="2"></textarea>` +
                        `</div>`
                    );
                    $(`#aigrader-ack-input-${id}`).trigger('focus');
                    return;
                }

                showAlert('error', strings.error, resp.message);
                return;
            }

            // Show saved state
            const $card = $('#feedback-' + id + ' .aigrader-feedback-card');
            $card.removeClass('aigrader-pending-approval').addClass('aigrader-approved');

            // Replace the editable area with static feedback. The label reports the mark the
            // server confirmed it stored, not the one the browser sent, so a rejected or
            // adjusted mark can never be displayed back as if it had been saved.
            const savedMark = resp.mark !== undefined ? parseFloat(resp.mark) : mark;
            const savedMax = responseMaxMark(resp);
            const gradeLabel = formatMark(savedMark) + '/' + (savedMax === null ? '?' : formatMark(savedMax));

            // The card is kept, not rebuilt: the assessor keeps the criteria breakdown and
            // the feedback in front of them, and only the controls change state. Rebuilding
            // it threw away the breakdown at the moment the decision was recorded.
            setRowStatus(id, 'approved');
            $('#saved-pill-' + id).prop('hidden', true);
            $('#review-hint-' + id).prop('hidden', true);
            $(`.aigrader-toggle-edit-btn[data-id="${id}"]`).prop('hidden', true);
            $('#edit-actions-' + id).prop('hidden', true);
            $('#grade-select-' + id).prop('disabled', true);
            $('#feedback-edit-' + id).prop('readonly', true).removeClass('is-editing');
            $('#suggested-' + id).text(gradeLabel);

            $btn.removeClass('aigrader-btn-primary ag-btn-primary')
                .addClass('aigrader-btn-saved')
                .prop('disabled', true)
                .html(`<svg class="ag-icon-sm ag-icon-fixed" xmlns="http://www.w3.org/2000/svg"
                        width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
                        viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/>
                        <polyline points="16 10 11 15 8 12"/></svg> `
                    + escapeHtml(strings.savedtogradebook));

            // Update grade column
            $('#grade-' + id)
                .text(gradeLabel)
                .removeClass('aigrader-grade-pending');

            showAlert('success', strings.success, strings.grade_saved);

            // Hide the card after a brief delay (since we only show ungraded)
            setTimeout(() => {
                hideGradedCard(id);
                // After card is removed, lock the next visible approve button
                lockNextApproveButton();
            }, 1500);
        })
        .fail((xhr) => {
            $btn.prop('disabled', false).html(strings.approve_save);
            // Surface the server's error message (e.g. 'Session expired') when available,
            // instead of always showing a generic 'Connection error'.
            var serverMsg = (xhr && xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : null;
            showAlert('error', strings.error, serverMsg || strings.error_connection);
        });
    }

    /**
     * Update the count on the Grade All button.
     *
     * Counts only the rows the current search leaves visible, because those are the
     * ones Grade All will act on.
     *
     * Update the essay count badge on Grade All button
     * Shows how many essays will be graded (respects search filter)
     * @return {void}
     */
    function updateEssayCount() {
        // Count ungraded essays (for Grade All)
        const ungradedCount = $('.aigrader-card:not(.aigrader-search-hidden) .aigrader-grade-btn:not(:disabled)').length;
        $('#aigrader-essay-count').text(ungradedCount);

        // Disable Grade All button if no essays to grade
        if (ungradedCount === 0) {
            $('#aigrader-gradeall-btn').prop('disabled', true).addClass('aigrader-btn-disabled');
        } else {
            $('#aigrader-gradeall-btn').prop('disabled', false).removeClass('aigrader-btn-disabled');
        }
    }

    /**
     * Grade every response the current search leaves visible.
     *
     * Two requests run at a time: enough to be quick on a large queue without putting a
     * burst of grading requests on the server for one assessor pressing one button.
     *
     * @return {void}
     */
    function gradeAll() {
        // Only select buttons that are NOT inside a hidden (search-filtered) card
        const buttons = $('.aigrader-card:not(.aigrader-search-hidden) .aigrader-grade-btn:not(:disabled)');

        if (!buttons.length) {
            showAlert('warning', strings.no_essays, strings.no_essays_message);
            return;
        }

        const total = buttons.length;
        const parallellimit = 2;
        const delaybetween = 100;
        let completed = 0;
        let currentindex = 0;
        let activerequests = 0;
        // Every response still in flight schedules gradeNext() when it finishes, so the
        // last few all arrive at the finished branch. Announcing completion there once per
        // arrival showed the assessor the same success alert two or three times.
        let announced = false;

        showLoading(true, `${strings.grading} 0/${total}`);

        /**
         * Start as many requests as the parallel limit allows, and announce the end once.
         *
         * @return {void}
         */
        function gradeNext() {
            if (completed >= total) {
                if (announced) {
                    return;
                }
                announced = true;
                showLoading(false);
                showAlert(
                    'success',
                    strings.grading_complete,
                    strings.grading_complete_message.replace('{$a}', total)
                );
                return;
            }

            while (activerequests < parallellimit && currentindex < total) {
                const $btn = $(buttons[currentindex]);
                currentindex++;
                activerequests++;
                gradeOneAsync($btn.data('qubaid'), $btn.data('slot'), $btn, oneFinished);
            }
        }

        /**
         * One response has come back: count it, free its slot, and look for the next.
         *
         * Declared outside the loop so every request shares the one set of counters, which
         * is what the progress figure is read from.
         *
         * @return {void}
         */
        function oneFinished() {
            completed++;
            activerequests--;
            showLoading(true, `${strings.grading} ${completed}/${total}`);
            setTimeout(gradeNext, delaybetween);
        }

        gradeNext();
    }

    /* ---------------------------------------------------------
     * gradeOneAsync - grade with callback for batch processing
     * --------------------------------------------------------- */

    /**
     * Remove an approved response's rows from the queue.
     *
     * Hide a card after it's been approved (graded)
     * This removes it from the view since we only show ungraded
     *
     * @param {String} id The row identifier, qubaid-slot.
     * @return {void}
     */
    function hideGradedCard(id) {
        const $card = $(`.aigrader-card[data-rowid="${id}"]`);
        if ($card.length) {
            // Fade the rows, not the tbody: animating a tbody's opacity is fine but
            // fadeOut ends by setting display:block on it, which collapses the table
            // layout for the frame before it is removed.
            // The callback fires once per row, so the teardown is guarded to run once.
            let removed = false;
            $card.find('tr').fadeTo(400, 0, function() {
                if (removed) {
                    return;
                }
                removed = true;
                $card.remove();
                // Update the essay count after removal
                updateEssayCount();
                // Check if no cards left
                if ($('.aigrader-card').length === 0) {
                    // Show "all graded" message
                    $('.aigrader-cards').html(`
                        <div class="aigrader-empty aigrader-all-graded">
                            <svg class="aigrader-empty-icon" xmlns="http://www.w3.org/2000/svg" width="64" height="64" fill="none"
                                    stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                        <polyline points="22 4 12 14.01 9 11.01"/>
                                    </svg>
                            <h3 class="aigrader-empty-title">${escapeHtml(strings.all_graded)}</h3>
                            <p class="aigrader-empty-message">${escapeHtml(strings.all_graded_message)}</p>
                        </div>
                    `);
                }
            });
        }
    }

    /**
     * Load and render the list of reference documents for this quiz.
     *
     * Reference documents - list
     * @return {void}
     */
    function loadDocuments() {
        const $list = $('#aigrader-documents-list');
        $list.html('<div class="aigrader-documents-loading">' + strings.loading + '</div>');

        $.post(config.ajaxurl, {
            action: 'listdocs',
            cmid: config.cmid,
            sesskey: config.sesskey
        }, null, 'json')
        .done(resp => {
            log('Documents response:', resp);

            if (!resp.ok || !resp.documents || resp.documents.length === 0) {
                $list.html('<div class="aigrader-no-documents">' + strings.no_documents + '</div>');
                return;
            }

            let html = '';
            resp.documents.forEach(doc => {
                const statusClass = 'aigrader-doc-status-' + doc.status;
                const statusText = strings['document_' + doc.status] || doc.status;
                const sizeKB = Math.round(doc.sizeBytes / 1024);

                html += `<div class="aigrader-doc-item" data-docid="${escapeHtml(doc.id)}">
                    <div class="aigrader-doc-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                             fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14 2 14 8 20 8"/>
                        </svg>
                    </div>
                    <div class="aigrader-doc-info">
                        <div class="aigrader-doc-name">${escapeHtml(doc.filename)}</div>
                        <div class="aigrader-doc-meta">${sizeKB} KB</div>
                    </div>
                    <span class="aigrader-doc-status ${statusClass}">${escapeHtml(statusText)}</span>
                    <button class="aigrader-doc-delete" data-docid="${escapeHtml(doc.id)}"
                            title="${escapeHtml(strings.document_delete)}">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                             fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <polyline points="3 6 5 6 21 6"/>
                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6
                                     m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                        </svg>
                    </button>
                </div>`;
            });

            $list.html(html);
        })
        .fail(() => {
            $list.html('<div class="aigrader-no-documents">' + strings.error_connection + '</div>');
        });
    }

    /**
     * Upload one reference document and refresh the list.
     *
     * Reference documents - upload
     *
     * @param {File} file The file the assessor chose.
     * @return {void}
     */
    function uploadDocument(file) {
        log('Uploading document:', file.name);

        const formData = new FormData();
        formData.append('file', file);
        formData.append('action', 'uploaddoc');
        formData.append('cmid', config.cmid);
        formData.append('sesskey', config.sesskey);

        showLoading(true, strings.extracting_text);

        $.ajax({
            url: config.ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json'
        })
        .done(resp => {
            showLoading(false);
            log('Upload response:', resp);

            if (resp.ok) {
                showAlert('success', strings.success, strings.document_uploaded);
                loadDocuments();
            } else {
                showAlert('error', strings.error, resp.error || strings.document_upload_error);
            }
        })
        .fail(() => {
            showLoading(false);
            showAlert('error', strings.error, strings.error_connection);
        });
    }

    /**
     * Delete one reference document, after the assessor confirms.
     *
     * Reference documents - delete
     *
     * @param {Number} docId The document to delete.
     * @return {void}
     */
    function deleteDocument(docId) {
        if (!confirm(strings.document_delete_confirm)) {
            return;
        }

        $.post(config.ajaxurl, {
            action: 'deletedoc',
            cmid: config.cmid,
            sesskey: config.sesskey,
            docid: docId
        }, null, 'json')
        .done(resp => {
            log('Delete response:', resp);

            if (resp.ok) {
                showAlert('success', strings.success, strings.document_deleted);
                loadDocuments();
            } else {
                showAlert('error', strings.error, resp.message || strings.error);
            }
        })
        .fail(() => {
            showAlert('error', strings.error, strings.error_connection);
        });
    }

    /**
     * Load this quiz's extra instructions and feedback language.
     *
     * Extra Instructions - load settings
     * @return {void}
     */
    function loadSettings() {
        log('Loading quiz settings...');

        $.post(config.ajaxurl, {
            action: 'getsettings',
            cmid: config.cmid,
            sesskey: config.sesskey
        }, null, 'json')
        .done(resp => {
            log('Settings response:', resp);

            if (resp.ok && resp.settings) {
                $('#aigrader-extra-instructions').val(resp.settings.extraInstructions || '');
                const savedLanguage = resp.settings.feedbackLanguage || 'en';
                // Use native JavaScript to set dropdown value (more reliable)
                const langSelect = document.getElementById('aigrader-feedback-language');
                if (langSelect) {
                    langSelect.value = savedLanguage;
                }
                // CRITICAL: Update config.language so grading uses the saved language
                config.language = savedLanguage;
                log('Language set from saved settings:', config.language);
            }
        })
        .fail(() => {
            log('Failed to load settings');
        });
    }

    /**
     * Save this quiz's extra instructions and feedback language.
     *
     * Extra Instructions - save settings
     * @return {void}
     */
    function saveSettings() {
        const instructions = $('#aigrader-extra-instructions').val();
        // Use native JavaScript to get dropdown value (more reliable than jQuery for selects)
        const langSelect = document.getElementById('aigrader-feedback-language');
        const feedbackLanguage = langSelect ? langSelect.value : (config.language || 'en');
        const $btn = $('#aigrader-save-instructions');
        const $status = $('#aigrader-instructions-status');

        log('Saving settings - language select value:', feedbackLanguage);

        $btn.prop('disabled', true);
        $status.html(`<span class="aigrader-spinner"></span>`);

        // CRITICAL: Update config.language immediately so grading uses the new language
        config.language = feedbackLanguage;
        log('Language updated on save:', config.language);

        $.post(config.ajaxurl, {
            action: 'savesettings',
            cmid: config.cmid,
            sesskey: config.sesskey,
            extraInstructions: instructions,
            feedbackLanguage: feedbackLanguage
        }, null, 'json')
        .done(resp => {
            log('Save settings response:', resp);
            $btn.prop('disabled', false);

            if (resp.ok) {
                $status.html(
                    `<svg class="aigrader-saved-icon" xmlns="http://www.w3.org/2000/svg"
                          width="16" height="16" fill="none" stroke="#22c55e"
                          stroke-width="2" viewBox="0 0 24 24">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg> ${escapeHtml(strings.instructions_saved)}`
                );
                setTimeout(() => $status.html(''), 3000);
            } else {
                $status.html(`<span class="aigrader-error-text">${escapeHtml(resp.message || strings.error)}</span>`);
            }
        })
        .fail(() => {
            $btn.prop('disabled', false);
            $status.html(`<span class="aigrader-error-text">${strings.error_connection}</span>`);
        });
    }

    /**
     * Fetch every string this module renders.
     *
     * The results are destructured positionally, so the order of the keys below and the
     * order of the assignments must stay in step.
     *
     * Load language strings required by module
     * @return {Promise} Resolves once the strings are held.
     */
    function loadStrings() {
        return Str.get_strings([
            {key: 'processing', component: 'quiz_aigrader'},
            {key: 'grading', component: 'quiz_aigrader'},
            {key: 'success', component: 'quiz_aigrader'},
            {key: 'error', component: 'quiz_aigrader'},
            {key: 'error_connection', component: 'quiz_aigrader'},
            {key: 'error_fetching_credits', component: 'quiz_aigrader'},
            {key: 'error_grading', component: 'quiz_aigrader'},
            {key: 'no_essays', component: 'quiz_aigrader'},
            {key: 'no_essays_message', component: 'quiz_aigrader'},
            {key: 'grading_complete', component: 'quiz_aigrader'},
            {key: 'feedback', component: 'quiz_aigrader'},
            {key: 'show_more', component: 'quiz_aigrader'},
            {key: 'show_less', component: 'quiz_aigrader'},
            {key: 'show_all_responses', component: 'quiz_aigrader'},
            {key: 'show_ungraded_only', component: 'quiz_aigrader'},
            {key: 'loading', component: 'quiz_aigrader'},
            {key: 'extracting_text', component: 'quiz_aigrader'},
            {key: 'no_documents', component: 'quiz_aigrader'},
            {key: 'document_ready', component: 'quiz_aigrader'},
            {key: 'document_processing', component: 'quiz_aigrader'},
            {key: 'document_failed', component: 'quiz_aigrader'},
            {key: 'document_delete', component: 'quiz_aigrader'},
            {key: 'document_delete_confirm', component: 'quiz_aigrader'},
            {key: 'document_uploaded', component: 'quiz_aigrader'},
            {key: 'document_upload_error', component: 'quiz_aigrader'},
            {key: 'document_deleted', component: 'quiz_aigrader'},
            {key: 'ai_grade', component: 'quiz_aigrader'},
            {key: 'pending_approval', component: 'quiz_aigrader'},
            {key: 'approve_save', component: 'quiz_aigrader'},
            {key: 'saving', component: 'quiz_aigrader'},
            {key: 'saved_to_gradebook', component: 'quiz_aigrader'},
            {key: 'grade_saved', component: 'quiz_aigrader'},
            {key: 'grade_label', component: 'quiz_aigrader'},
            {key: 'grade_0', component: 'quiz_aigrader'},
            {key: 'grade_1', component: 'quiz_aigrader'},
            {key: 'grade_2', component: 'quiz_aigrader'},
            {key: 'grade_3', component: 'quiz_aigrader'},
            {key: 'instructions_saved', component: 'quiz_aigrader'},
            {key: 'regrading', component: 'quiz_aigrader'},
            {key: 'regrading_complete', component: 'quiz_aigrader'},
            {key: 'no_graded_essays', component: 'quiz_aigrader'},
            {key: 'no_graded_essays_message', component: 'quiz_aigrader'},
            {key: 'confirm_regrade', component: 'quiz_aigrader'},
            {key: 'feedback_strengths', component: 'quiz_aigrader'},
            {key: 'feedback_improvements', component: 'quiz_aigrader'},
            {key: 'feedback_suggestions', component: 'quiz_aigrader'},
            {key: 'feedback_other', component: 'quiz_aigrader'},
            {key: 'feedback_none', component: 'quiz_aigrader'},
            {key: 'feedback_notrecorded', component: 'quiz_aigrader'},
            {key: 'notgraded', component: 'quiz_aigrader'},
            {key: 'previousattempt', component: 'quiz_aigrader'},
            {key: 'edit_feedback', component: 'quiz_aigrader'},
            {key: 'update_preview', component: 'quiz_aigrader'},
            {key: 'review_countdown', component: 'quiz_aigrader'},
            {key: 'no_grading_data', component: 'quiz_aigrader'},
            {key: 'attemptnumber', component: 'quiz_aigrader'},
            {key: 'attemptnumberofmax', component: 'quiz_aigrader'},
            {key: 'all_graded', component: 'quiz_aigrader'},
            {key: 'all_graded_message', component: 'quiz_aigrader'},
            {key: 'error_nomaxmark', component: 'quiz_aigrader'},
            {key: 'error_nomarkselected', component: 'quiz_aigrader'},
            {key: 'criteria_heading', component: 'quiz_aigrader'},
            {key: 'policy_withheld', component: 'quiz_aigrader'},
            {key: 'policy_not_applied', component: 'quiz_aigrader'},
            {key: 'suggested_grade', component: 'quiz_aigrader'},
            {key: 'feedback_to_learner', component: 'quiz_aigrader'},
            {key: 'edit', component: 'quiz_aigrader'},
            {key: 'save_changes', component: 'quiz_aigrader'},
            {key: 'cancel', component: 'quiz_aigrader'},
            {key: 'changes_saved', component: 'quiz_aigrader'},
            {key: 'savedtogradebook', component: 'quiz_aigrader'},
            {key: 'read_answer_first', component: 'quiz_aigrader'},
            {key: 'status_pending', component: 'quiz_aigrader'},
            {key: 'status_grading', component: 'quiz_aigrader'},
            {key: 'status_graded', component: 'quiz_aigrader'},
            {key: 'status_approved', component: 'quiz_aigrader'},
            {key: 'criteria_total', component: 'quiz_aigrader'},
            {key: 'grading_complete_message', component: 'quiz_aigrader'}
        ]).then(result => {
            [
                strings.processing,
                strings.grading,
                strings.success,
                strings.error,
                strings.error_connection,
                strings.error_fetching_credits,
                strings.error_grading,
                strings.no_essays,
                strings.no_essays_message,
                strings.grading_complete,
                strings.feedback,
                strings.show_more,
                strings.show_less,
                strings.show_all_responses,
                strings.show_ungraded_only,
                strings.loading,
                strings.extracting_text,
                strings.no_documents,
                strings.document_ready,
                strings.document_processing,
                strings.document_failed,
                strings.document_delete,
                strings.document_delete_confirm,
                strings.document_uploaded,
                strings.document_upload_error,
                strings.document_deleted,
                strings.ai_grade,
                strings.pending_approval,
                strings.approve_save,
                strings.saving,
                strings.saved_to_gradebook,
                strings.grade_saved,
                strings.grade_label,
                strings.grade_0,
                strings.grade_1,
                strings.grade_2,
                strings.grade_3,
                strings.instructions_saved,
                strings.regrading,
                strings.regrading_complete,
                strings.no_graded_essays,
                strings.no_graded_essays_message,
                strings.confirm_regrade,
                strings.feedback_strengths,
                strings.feedback_improvements,
                strings.feedback_suggestions,
                strings.feedback_other,
                strings.feedback_none,
                strings.feedback_notrecorded,
                strings.notgraded,
                strings.previousattempt,
                strings.edit_feedback,
                strings.update_preview,
                strings.review_countdown,
                strings.no_grading_data,
                strings.attemptnumber,
                strings.attemptnumberofmax,
                strings.all_graded,
                strings.all_graded_message,
                strings.error_nomaxmark,
                strings.error_nomarkselected,
                strings.criteria_heading,
                strings.policy_withheld,
                strings.policy_not_applied,
                strings.suggested_grade,
                strings.feedback_to_learner,
                strings.edit,
                strings.save_changes,
                strings.cancel,
                strings.changes_saved,
                strings.savedtogradebook,
                strings.read_answer_first,
                strings.status_pending,
                strings.status_grading,
                strings.status_graded,
                strings.status_approved,
                strings.criteria_total,
                strings.grading_complete_message
            ] = result;

            return true;
        });
    }

    /**
     * Attach every event handler the report needs.
     * @return {void}
     */
    function registerHandlers() {
        $(document).on('click', '#aigrader-refresh-btn', fetchCredits);

        $(document).on('click', '.aigrader-expand-btn', function(e) {
            e.preventDefault();
            const id = $(this).data('rowid');
            setRowExpanded(id, $('#detail-' + id).prop('hidden'));
        });

        // Clicking anywhere on the summary row toggles it too, except on a link or button,
        // which have their own jobs.
        $(document).on('click', '.aigrader-qrow', function(e) {
            if ($(e.target).closest('a, button').length) {
                return;
            }
            const id = $(this).closest('.aigrader-card').data('rowid');
            if (id !== undefined) {
                setRowExpanded(id, $('#detail-' + id).prop('hidden'));
            }
        });

        // The assessor scores a criterion: clamp it, store it, and let the mark follow.
        $(document).on('input change', '.aigrader-crit-input', function() {
            const id = $(this).data('card');
            const i = parseInt($(this).data('index'), 10);
            const criteria = readCriteria(id);
            if (!criteria[i]) {
                return;
            }
            const max = criteria[i].max > 0 ? criteria[i].max : 1;
            let v = parseFloat($(this).val());
            if (!isFinite(v)) {
                v = 0;
            }
            v = Math.min(Math.max(roundMark(v), 0), max);
            criteria[i].awarded = v;
            $('#criteriadata-' + id).val(JSON.stringify(criteria));
            applyCriteriaScores(id);
        });

        // Put a cleaned value back in the box when the assessor leaves it, so a typed
        // 99 on a 10-mark criterion does not sit there looking accepted.
        $(document).on('blur', '.aigrader-crit-input', function() {
            const id = $(this).data('card');
            const i = parseInt($(this).data('index'), 10);
            const criteria = readCriteria(id);
            if (criteria[i]) {
                $(this).val(formatMark(criteria[i].awarded));
            }
        });

        // Re-derive the feedback whenever the assessor changes the mark, so the text in the
        // editor always matches the judgement being made.
        $(document).on('change', '.aigrader-grade-dropdown', function() {
            const id = $(this).attr('id').replace(/^grade-select-/, '');
            refreshFeedbackForCard(id);
        });

        $(document).on('click', '.aigrader-grade-btn', function() {
            gradeOne($(this).data('qubaid'), $(this).data('slot'), $(this));
        });

        $(document).on('click', '#aigrader-gradeall-btn', gradeAll);


        $(document).on('click', '.aigrader-answer-toggle', function() {
            // Find either .aigrader-answer or .aigrader-question (both can be truncated)
            let $txt = $(this).siblings('.aigrader-answer');
            if ($txt.length === 0) {
                $txt = $(this).siblings('.aigrader-question');
            }

            if ($txt.hasClass('aigrader-answer-truncated')) {
                $txt.removeClass('aigrader-answer-truncated')
                    .text($txt.data('full'));
                $(this).text(strings.show_less);
            } else {
                $txt.addClass('aigrader-answer-truncated');
                $(this).text(strings.show_more);
            }
        });

        // Search handler - works with new card-based layout
        // Searches student name, question text, and answer content
        $(document).on('input', '#aigrader-search', function() {
            const searchTerm = $(this).val().toLowerCase().trim();
            const $cards = $('.aigrader-card');

            $cards.each(function() {
                const $card = $(this);
                const studentName = $card.data('studentname') || '';
                const questionText = $card.data('questiontext') || '';
                const answerText = $card.data('answer') || '';

                // Match against student name, question, or answer
                const matches = searchTerm === '' ||
                    studentName.indexOf(searchTerm) !== -1 ||
                    questionText.indexOf(searchTerm) !== -1 ||
                    answerText.indexOf(searchTerm) !== -1;

                if (matches) {
                    $card.removeClass('aigrader-search-hidden');
                } else {
                    $card.addClass('aigrader-search-hidden');
                }
            });

            // Update the count after search filter is applied
            updateEssayCount();
        });

        // Document section toggle
        $(document).on('click', '#aigrader-documents-header, #aigrader-documents-toggle', function(e) {
            e.stopPropagation();
            $('#aigrader-documents').toggleClass('is-collapsed');
        });

        // Document upload handler
        $(document).on('change', '#aigrader-doc-input', function() {
            const file = this.files[0];
            if (file) {
                uploadDocument(file);
                this.value = ''; // Reset input
            }
        });

        // Document delete handler
        $(document).on('click', '.aigrader-doc-delete', function(e) {
            e.stopPropagation();
            const docId = $(this).data('docid');
            deleteDocument(docId);
        });

        // Auto-resize feedback textarea as user types
        $(document).on('input', '.aigrader-feedback-edit', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight + 4) + 'px';
        });

        // Approve grade handler
        $(document).on('click', '.aigrader-approve-btn', function(e) {
            e.preventDefault();
            const qubaid = $(this).data('qubaid');
            const slot = $(this).data('slot');
            const id = $(this).data('id');
            approveGrade(qubaid, slot, id);
        });

        // Toggle edit section (for pending approval feedback)
        // Feedback editing.
        //
        // The textarea is always visible and starts read-only, as the reference screens show:
        // the assessor sees exactly what the learner will receive without having to open an
        // editor, and Edit turns it into a field with explicit Save changes / Cancel.
        /**
         * Switch a response's feedback between read-only and editable.
         *
         * @param {String} id The row identifier, qubaid-slot.
         * @param {Boolean} editing True to let the assessor type, false to lock it.
         * @return {void}
         */
        function setEditing(id, editing) {
            const $ta = $('#feedback-edit-' + id);
            $ta.prop('readonly', !editing).toggleClass('is-editing', editing);
            $(`.aigrader-toggle-edit-btn[data-id="${id}"]`).prop('hidden', editing);
            $('#edit-actions-' + id).prop('hidden', !editing);
            if (editing) {
                $ta.data('ag-before', $ta.val());
                $ta[0].style.height = 'auto';
                $ta[0].style.height = Math.max(140, $ta[0].scrollHeight + 8) + 'px';
                $ta.trigger('focus');
            }
        }

        $(document).on('click', '.aigrader-toggle-edit-btn', function(e) {
            e.preventDefault();
            const id = $(this).data('id');
            $('#saved-pill-' + id).prop('hidden', true);
            setEditing(id, true);
        });

        $(document).on('click', '.aigrader-save-edit-btn', function(e) {
            e.preventDefault();
            const id = $(this).data('id');
            setEditing(id, false);
            refreshPreviewFromTextarea(id);
            // Nothing has reached the gradebook yet - this confirms the edit was kept, which
            // is why the wording is "Changes saved" and not "Saved".
            $('#saved-pill-' + id).prop('hidden', false);
        });

        $(document).on('click', '.aigrader-cancel-edit-btn', function(e) {
            e.preventDefault();
            const id = $(this).data('id');
            const $ta = $('#feedback-edit-' + id);
            const before = $ta.data('ag-before');
            if (before !== undefined) {
                $ta.val(before);
            }
            setEditing(id, false);
            refreshPreviewFromTextarea(id);
        });

        // Keep the preview in step with the textarea as the assessor types.
        //
        // The preview only updated when the button was pressed, so an assessor could edit
        // the feedback, not press it, and approve: the right text was saved, but the card
        // they were looking at showed the old version. What they visually approved was not
        // what the learner received. Debounced so a long edit does not re-render per key.
        let previewTimers = {};
        /**
         * Re-render the learner-facing preview from what the assessor has typed.
         *
         * @param {String} id The row identifier, qubaid-slot.
         * @return {void}
         */
        function refreshPreviewFromTextarea(id) {
            const rawText = $('#feedback-edit-' + id).val();
            $('#feedback-preview-' + id).html(
                ($('#criteria-' + id).val() || '') + formatFeedbackHtml(rawText)
            );
        }

        $(document).on('input', '.aigrader-feedback-edit', function() {
            const id = $(this).attr('id').replace(/^feedback-edit-/, '');
            clearTimeout(previewTimers[id]);
            previewTimers[id] = setTimeout(() => refreshPreviewFromTextarea(id), 400);
        });

        $(document).on('blur', '.aigrader-feedback-edit', function() {
            const id = $(this).attr('id').replace(/^feedback-edit-/, '');
            clearTimeout(previewTimers[id]);
            refreshPreviewFromTextarea(id);
        });

        // The button stays for assessors who expect it, and now does the same thing.
        $(document).on('click', '.aigrader-update-preview-btn', function(e) {
            e.preventDefault();
            refreshPreviewFromTextarea($(this).data('id'));
        });

        // Instructions section toggle
        $(document).on('click', '#aigrader-instructions-toggle', function(e) {
            e.stopPropagation();
            $('.aigrader-instructions-section').toggleClass('is-collapsed');
        });

        // Previous attempt toggle (collapsible section for multi-attempt context)
        $(document).on('click', '.aigrader-previous-toggle', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const $toggle = $(this);
            const $content = $toggle.siblings('.aigrader-previous-content');
            const $chevron = $toggle.find('.aigrader-chevron');
            const isExpanded = $toggle.attr('data-expanded') === 'true';

            if (isExpanded) {
                $content.slideUp(200);
                $chevron.css('transform', 'rotate(0deg)');
                $toggle.attr('data-expanded', 'false');
            } else {
                $content.slideDown(200);
                $chevron.css('transform', 'rotate(90deg)');
                $toggle.attr('data-expanded', 'true');
            }
        });

        // Save instructions handler
        $(document).on('click', '#aigrader-save-instructions', function(e) {
            e.preventDefault();
            saveSettings();
        });

        // Language dropdown change handler - update config.language immediately (using native JS)
        $(document).on('change', '#aigrader-feedback-language', function() {
            const langSelect = document.getElementById('aigrader-feedback-language');
            config.language = langSelect ? langSelect.value : 'en';
            log('Language changed to:', config.language);
        });

        // Grading stats toggle
        $(document).on('click', '#aigrader-stats-toggle', function() {
            $('#aigrader-stats-content').slideToggle(200);
            $(this).find('svg').toggleClass('aigrader-chevron-rotated');
        });

        // Grading stats apply filters
        $(document).on('click', '#aigrader-stats-apply', function() {
            loadGradingStats();
        });
    }

    /**
     * Load the grading statistics shown in the report header.
     *
     * Load grading stats for the stats box
     * @return {void}
     */
    function loadGradingStats() {
        var courseid = $('#aigrader-filter-course').val() || 0;
        var graderid = $('#aigrader-filter-grader').val() || 0;
        var datefrom = $('#aigrader-filter-datefrom').val();
        var dateto = $('#aigrader-filter-dateto').val();

        $.post(config.ajaxurl, {
            action: 'gradingstats',
            cmid: config.cmid,
            sesskey: config.sesskey,
            courseid: courseid,
            graderid: graderid,
            datefrom: datefrom ? Math.floor(new Date(datefrom).getTime() / 1000) : 0,
            dateto: dateto ? Math.floor(new Date(dateto + 'T23:59:59').getTime() / 1000) : 0
        }, null, 'json')
        .done(function(resp) {
            if (!resp.ok) {
                // Previously this branch did nothing at all, so a server-side failure left
                // every figure on its placeholder with no indication that anything was wrong.
                log('Grading stats request returned an error:', resp.message || resp.error_code || resp);
                return;
            }
            if (resp.ok) {
                // Each figure falls back rather than being concatenated as it arrives. The
                // average was `resp.avgSecondsPerEssay + 's'`, which printed "undefineds"
                // in the statistics box on any ok response that carried no figures - the
                // line below it already guarded against exactly that.
                $('#aigrader-stat-essays').text(resp.totalEssays !== undefined ? resp.totalEssays : 0);
                $('#aigrader-stat-time').text(resp.totalTimeFormatted || '0:00:00');
                $('#aigrader-stat-avg').text(
                    resp.avgSecondsPerEssay !== undefined ? resp.avgSecondsPerEssay + 's' : '0s'
                );
                $('#aigrader-stat-student-time').text(resp.totalStudentTimeFormatted || '0:00:00');

                // Populate the filter dropdowns, once.
                //
                // Built through the DOM rather than by concatenating HTML. A course
                // shortname or a grader's own name can contain the characters that end an
                // attribute or open a tag, and this panel shows one assessor's name to
                // every other assessor, so concatenation here was a stored-XSS route.
                // .text() and .val() escape for the position they write into; escapeHtml()
                // would not have, because it leaves quotes alone.
                if ($('#aigrader-filter-course option').length <= 1 && resp.filterOptions) {
                    resp.filterOptions.courses.forEach(function(c) {
                        $('#aigrader-filter-course').append(
                            $('<option>').val(c.id).text(c.shortname)
                        );
                    });
                    resp.filterOptions.graders.forEach(function(g) {
                        $('#aigrader-filter-grader').append(
                            $('<option>').val(g.id).text(g.name)
                        );
                    });
                }

                // Populate the graders table.
                var tbody = $('#aigrader-stats-tbody');
                tbody.empty();
                if (!resp.graders || resp.graders.length === 0) {
                    tbody.append(
                        $('<tr>').append(
                            $('<td>')
                                .attr('colspan', 4)
                                .addClass('aigrader-stats-empty')
                                .text(strings.no_grading_data)
                        )
                    );
                } else {
                    resp.graders.forEach(function(g) {
                        tbody.append(
                            $('<tr>')
                                .append($('<td>').text(g.name))
                                .append($('<td>').text(g.essays))
                                .append($('<td>').text(g.timeFormatted))
                                .append($('<td>').text(g.avgSecondsPerEssay + 's'))
                        );
                    });
                }
            }
        })
        .fail(function() {
            log('Failed to load grading stats');
        });
    }

    return {
        init: function(cfg) {
            config = cfg || {};

            log('Raw config received:', JSON.stringify(config));

            // If config is not an object (AMD passing issue), try to recover
            if (typeof config !== 'object' || config === null) {
                config = {};
            }

            // Try to get ajaxurl from multiple sources
            if (!config.ajaxurl) {
                // Source 1: data attribute on container
                var container = document.getElementById('aigrader-root');
                if (container && container.dataset.ajaxurl) {
                    config.ajaxurl = container.dataset.ajaxurl;
                    log('Got ajaxurl from data attribute:', config.ajaxurl);
                }
                // Source 2: M.cfg.wwwroot
                else if (typeof M !== 'undefined' && M.cfg && M.cfg.wwwroot) {
                    config.ajaxurl = M.cfg.wwwroot + '/mod/quiz/report/aigrader/ajax.php';
                    log('Built ajaxurl from M.cfg.wwwroot:', config.ajaxurl);
                }
                // Source 3: Current page URL
                else {
                    config.ajaxurl = window.location.origin + '/mod/quiz/report/aigrader/ajax.php';
                    log('Built ajaxurl from origin:', config.ajaxurl);
                }
            }

            // Get other config from data attributes if missing
            if (!config.cmid) {
                var cmidMatch = window.location.search.match(/id=(\d+)/);
                if (cmidMatch) {
                    config.cmid = cmidMatch[1];
                }
            }
            if (!config.sesskey && typeof M !== 'undefined' && M.cfg) {
                config.sesskey = M.cfg.sesskey;
            }

            log('Final config:', JSON.stringify(config));

            loadStrings()
                .then(() => {
                    fetchCredits();
                    loadGradingStats();
                    loadDocuments();
                    loadSettings();
                    registerHandlers();
                    updateEssayCount(); // Initialize essay count on page load
                })
                .catch(Notification.exception);
        }
    };
});
