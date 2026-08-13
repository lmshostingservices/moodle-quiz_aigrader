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
 */
define(['jquery', 'core/str', 'core/notification'], function($, Str, Notification) {

    let config = {};
    let strings = {};

    /* ---------------------------------------------------------
     * Utility logging
     * --------------------------------------------------------- */
    function log(msg, data) {
        if (window.console) {
            console.log('[AI Grader] ' + msg, data ?? '');
        }
    }

    /* ---------------------------------------------------------
     * Loading overlay
     * --------------------------------------------------------- */
    function showLoading(show, text) {
        const $overlay = $('#aigrader-loading');
        if (!$overlay.length) return;

        if (show) {
            $overlay.find('.aigrader-loading-text').text(text || strings.processing);
            $overlay.addClass('is-visible');
        } else {
            $overlay.removeClass('is-visible');
        }
    }

    /* ---------------------------------------------------------
     * Alerts
     * --------------------------------------------------------- */
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
                    <p class="aigrader-alert-title">${title}</p>
                    <p class="aigrader-alert-message">${message}</p>
                </div>
            </div>`;

        $('.aigrader-header').after(html);

        setTimeout(() => {
            $('.aigrader-alert').fadeOut(300, function() {
                $(this).remove();
            });
        }, 4000);
    }

    /* ---------------------------------------------------------
     * Credits badge update
     * --------------------------------------------------------- */
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
     * Format feedback into styled cards - with inline styles
     * Uses inline styles so it looks good in Moodle's review attempt view
     * Three sections: success (green), warning (amber), info (blue)
     * --------------------------------------------------------- */
    function formatFeedbackHtml(text, grade100) {
        // Defensive: ensure text is a string
        if (typeof text !== 'string') {
            log('formatFeedbackHtml received non-string:', typeof text, text);
            if (text === null || text === undefined) {
                return '<div style="color: #6b7280; font-style: italic;">No feedback available</div>';
            }
            // Try to stringify objects
            try {
                text = JSON.stringify(text, null, 2);
            } catch (e) {
                text = String(text);
            }
        }
        
        // Parse text into sections
        const sections = [];
        let currentSection = null;
        
        // Comprehensive regex to strip all bullet prefixes consistently
        // Covers: bullets (*****), squares (**), triangles ( >  > ), dashes (--- -  - ), middle dots (***), arrows ( ->  =>  > )
        // Also covers colored circle emoji: [green][orange][blue][purple][yellow][red][black-circle][white-circle] (AI often returns these as bullet markers)
        // Applied to handle double bullets like "* *" and numbered lists like "1." or "1)" or "1:"
        // Uses + quantifier to strip consecutive bullet chars, and repeats if needed
        const STRIP = /^(?:[******* >  > >>**--- -  - *** ->  =>  > \u2022\u2023\u2043\u25CF\u25CB\u25E6\u25AA\u25AB\u25B8\u25B9\u25BA\u25BB\u25C6\u25C7\u2010\u2011\u2012\u2013\u2014\u00B7\u2219\u22C5\u2192\u21D2\u27A4\u{1F7E0}\u{1F7E1}\u{1F7E2}\u{1F7E3}\u{1F7E4}\u{1F534}\u{1F535}\u{1F7E5}\u{1F7E6}\u{1F7E7}\u{1F7E8}\u{1F7E9}\u{1F7EA}\u{1F7EB}\u26AB\u26AA\u2B24]+\s*|\d+[.):]\s*|[-*+]\s+)/u;
        
        const lines = text.split('\n');
        
        for (let i = 0; i < lines.length; i++) {
            const line = lines[i].trim();
            if (!line) continue;
            
            const lower = line.toLowerCase();
            
            // Detect section headers - by emoji first (works in any language), then by English text
            // v8.4.55: Lines longer than 80 chars are content, not headers - prevents
            // sentences like "There are no significant areas for improvement" from
            // creating a duplicate orange box
            const isShortEnough = line.length <= 80;
            if (isShortEnough && /\[thumbs-up\]|what you did well|strengths|good work|well done/i.test(line)) {
                if (currentSection) sections.push(currentSection);
                // Extract title after emoji or use default
                const title = line.replace(/^(\[thumbs-up\]|\s)+/, '').trim() || 'What you did well';
                currentSection = { type: 'success', title: title, icon: '[thumbs-up]', items: [] };
            } else if (isShortEnough && /\[chart-down\]|what needs improvement|areas for improvement|needs work|could improve/i.test(line)) {
                if (currentSection) sections.push(currentSection);
                const title = line.replace(/^(\[chart-down\]|\s)+/, '').trim() || 'What needs improvement';
                currentSection = { type: 'warning', title: title, icon: '[chart-down]', items: [] };
            } else if (isShortEnough && /\[tip\]|\u2753|\\u{2753}|how to improve|suggestions|recommendations|next steps|tips|kaizen-houhou|kaizen-kaitou/iu.test(line)) {
                if (currentSection) sections.push(currentSection);
                const title = line.replace(/^(\[tip\]|\u2753|\s)+/u, '').trim() || 'How to improve your answer';
                currentSection = { type: 'info', title: title, icon: '[tip]', items: [] };
            } else if (currentSection) {
                // Add as bullet item - strip bullets/emoji repeatedly for double bullets like "* * text"
                let cleanText = line;
                let prevLen = 0;
                while (cleanText.length !== prevLen && STRIP.test(cleanText)) {
                    prevLen = cleanText.length;
                    cleanText = cleanText.replace(STRIP, '').trim();
                }
                if (cleanText) {
                    currentSection.items.push(cleanText);
                }
            }
        }
        
        // Push last section
        if (currentSection) sections.push(currentSection);
        
        // SVG icons for each section type (inline SVG so they work everywhere)
        const icons = {
            success: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;min-width:18px;min-height:18px;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
            warning: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;min-width:18px;min-height:18px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
            info: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;min-width:18px;min-height:18px;"><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/><circle cx="12" cy="12" r="10"/></svg>'
        };

        // Bullet icons - small checkmark for success, small dash for warning/info
        const bulletIcons = {
            success: '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;min-width:14px;min-height:14px;margin-top:3px;"><polyline points="20 6 9 17 4 12"/></svg>',
            warning: '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;min-width:14px;min-height:14px;margin-top:3px;"><circle cx="12" cy="12" r="4" fill="currentColor" stroke="none"/></svg>',
            info: '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;min-width:14px;min-height:14px;margin-top:3px;"><circle cx="12" cy="12" r="4" fill="currentColor" stroke="none"/></svg>'
        };

        // Style configs - used as inline fallback AND via CSS classes
        const styles = {
            success: {
                cardClass: 'feedback-card feedback-card-success',
                bg: 'linear-gradient(135deg, #dcfce7 0%, #f0fdf4 100%)',
                border: '1px solid #86efac',
                titleColor: '#166534',
                bulletColor: '#166534',
                textColor: '#1e293b'
            },
            warning: {
                cardClass: 'feedback-card feedback-card-warning',
                bg: 'linear-gradient(135deg, #fef3c7 0%, #fffbeb 100%)',
                border: '1px solid #fcd34d',
                titleColor: '#92400e',
                bulletColor: '#92400e',
                textColor: '#1e293b'
            },
            info: {
                cardClass: 'feedback-card feedback-card-info',
                bg: 'linear-gradient(135deg, #ede9fe 0%, #f5f3ff 100%)',
                border: '1px solid #c4b5fd',
                titleColor: '#5b21b6',
                bulletColor: '#5b21b6',
                textColor: '#1e293b'
            }
        };
        
        // Build HTML using CSS classes (premium on grading page) + inline fallbacks (review page)
        let html = '<div style="font-family: Inter, system-ui, sans-serif; line-height: 1.6;">';
        
        for (const section of sections) {
            // v8.4.5: Hide "suggestions/how to improve" box when student achieved full marks
            if (section.type === 'info' && parseInt(grade100) >= 100) continue;
            const s = styles[section.type] || styles.info;
            const icon = icons[section.type] || icons.info;
            const bulletIcon = bulletIcons[section.type] || bulletIcons.info;
            
            html += '<div class="' + s.cardClass + '" style="background:' + s.bg + ';border:' + s.border + ';border-radius:12px;padding:16px 20px;margin-bottom:16px;">';
            html += '<div class="feedback-card-title" style="font-size:14px;font-weight:600;color:' + s.titleColor + ';margin-bottom:12px;display:flex;align-items:center;gap:8px;">';
            html += '<span style="color:' + s.titleColor + ';">' + icon + '</span>';
            html += escapeHtml(section.title);
            html += '</div>';
            
            if (section.items.length > 0) {
                html += '<div class="feedback-card-list" style="margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:6px;">';
                for (const item of section.items) {
                    html += '<div class="feedback-card-item" style="display:flex;align-items:flex-start;gap:10px;font-size:13px;line-height:1.6;color:' + s.textColor + ';">';
                    html += '<span class="feedback-bullet" style="color:' + s.bulletColor + ';">' + bulletIcon + '</span>';
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
            html = '<div class="feedback-plain" style="font-family: Inter, system-ui, sans-serif; line-height: 1.7; color: #374151;">';
            for (const p of paragraphs) {
                html += '<p style="margin: 0 0 12px 0;">' + escapeHtml(p.trim()) + '</p>';
            }
            html += '</div>';
        }
        
        return html;
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /* ---------------------------------------------------------
     * Grade ONE essay - shows editable feedback for teacher approval
     * --------------------------------------------------------- */
    function gradeOne(qubaid, slot, $btn) {
        const id = `${qubaid}-${slot}`;

        $btn.prop('disabled', true)
            .html(`<span class="aigrader-spinner"></span> ${strings.grading}`);

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
                showAlert('error', strings.error_grading, resp.message);
                return;
            }

            // Generate dynamic grade options based on maxmark from response (whole marks only)
            const maxMark = resp.maxmark || 3;
            let gradeOptions = '';
            for (let i = 0; i <= maxMark; i++) {
                const pct = Math.round((i / maxMark) * 100);
                const selected = pct === resp.grade100 ? 'selected' : '';
                gradeOptions += `<option value="${pct}" ${selected}>${i}/${maxMark}</option>`;
            }

            // Format feedback into beautiful cards for preview
            // Handle case where OpenAI returns feedback as object instead of string
            let feedbackRaw = resp.feedback || '';
            if (typeof feedbackRaw === 'object') {
                // Convert object to formatted string
                if (feedbackRaw.sections) {
                    // Handle structured sections format
                    feedbackRaw = Object.entries(feedbackRaw.sections || feedbackRaw)
                        .map(([key, val]) => `${key}:\n${Array.isArray(val) ? val.join('\n') : val}`)
                        .join('\n\n');
                } else {
                    // Generic object - stringify with formatting
                    feedbackRaw = Object.entries(feedbackRaw)
                        .map(([key, val]) => {
                            const header = key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
                            const content = Array.isArray(val) ? val.map(v => `- ${v}`).join('\n') : val;
                            return `${header}:\n${content}`;
                        })
                        .join('\n\n');
                }
            }
            const feedbackPreview = formatFeedbackHtml(feedbackRaw, resp.grade100);
            
            // DEBUG: Previous Attempts Feature
            console.log("[AI Grader] Attempt Context:", { attemptNum: resp.attemptnum, maxAttempts: resp.maxattempts, previousAttempt: resp.previousattempt });
            
            // Build attempt context badge
            const attemptNum = resp.attemptnum || 1;
            const maxAttempts = resp.maxattempts || 0;
            const attemptBadge = maxAttempts > 0 
                ? `<span class="aigrader-attempt-badge ag-badge ag-badge-info">Attempt ${attemptNum} of ${maxAttempts}</span>`
                : `<span class="aigrader-attempt-badge ag-badge ag-badge-info">Attempt ${attemptNum}</span>`;
            
            // Build previous attempt section if available
            let previousAttemptHtml = '';
            if (resp.previousattempt && (resp.previousattempt.feedback || resp.previousattempt.grade !== null)) {
                const prevGrade = resp.previousattempt.grade;
                const prevFeedback = resp.previousattempt.feedback || 'No feedback recorded';
                const prevGradeDisplay = prevGrade !== null ? `${prevGrade}%` : 'Not graded';
                const prevGradeBadgeClass = prevGrade >= 100 ? 'ag-badge-success' : (prevGrade >= 50 ? 'ag-badge-warning' : 'ag-badge-error');
                previousAttemptHtml = `
                    <div class="aigrader-previous-attempt">
                        <button type="button" class="aigrader-previous-toggle" data-expanded="false">
                            <svg class="aigrader-chevron ag-icon-xs" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="9 18 15 12 9 6"/>
                            </svg>
                            <span>Previous Attempt</span>
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
                        <div class="aigrader-feedback-header ag-flex-between ag-flex-wrap ag-gap-sm">
                            <div class="ag-flex-center ag-gap-sm ag-flex-wrap">
                                <svg class="aigrader-feedback-icon ag-icon-sm ag-icon-fixed"
                                     xmlns="http://www.w3.org/2000/svg"
                                     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>
                                </svg>
                                <span class="aigrader-feedback-label">${strings.feedback}</span>
                                ${attemptBadge}
                                ${resp.score ? `<span class="aigrader-score-badge ag-badge ag-badge-success">${resp.score}</span>` : ''}
                                <span class="aigrader-pending-badge ag-badge ag-badge-pending">${strings.pending_approval}</span>
                            </div>
                            <div class="aigrader-grade-select ag-flex-center ag-gap-sm">
                                <label>${strings.grade_label}</label>
                                <select class="aigrader-grade-dropdown" id="grade-select-${id}">
                                    ${gradeOptions}
                                </select>
                            </div>
                        </div>
                        ${previousAttemptHtml}
                        <div class="aigrader-feedback-preview" id="feedback-preview-${id}">${feedbackPreview}</div>
                        <div class="aigrader-edit-toggle">
                            <button type="button" class="aigrader-btn aigrader-btn-sm aigrader-btn-ghost aigrader-toggle-edit-btn" data-id="${id}">
                                <svg class="ag-icon-xs" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                </svg>
                                Edit feedback
                            </button>
                        </div>
                        <div class="aigrader-edit-section" id="edit-section-${id}" style="display: none;">
                            <textarea class="aigrader-feedback-edit" id="feedback-edit-${id}">${feedbackRaw}</textarea>
                            <button type="button" class="aigrader-btn aigrader-btn-sm aigrader-btn-secondary aigrader-update-preview-btn" data-id="${id}">
                                Update Preview
                            </button>
                        </div>
                        <div class="aigrader-approval-actions">
                            <button type="button" class="aigrader-btn aigrader-btn-primary aigrader-approve-btn ag-btn-base ag-btn-primary"
                                    data-qubaid="${qubaid}" data-slot="${slot}" data-id="${id}"
                                    data-autolocked="${resp.autolocked ? 1 : 0}" data-humanreview="${resp.humanreview ? 1 : 0}">
                                <svg class="ag-icon-sm ag-icon-fixed" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <polyline points="20 6 9 17 4 12"/>
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
            $('#feedback-edit-' + id).val(feedbackRaw);

            // Update grade label to show pending
            $('#grade-' + id)
                .text(resp.grade)
                .addClass('aigrader-grade-pending');

            // credits updated
            if (resp.credits !== undefined) {
                updateCredits(resp.credits);
            }

            // Hide the AI Grade button (replaced by Approve flow)
            // Use multiple methods to ensure it's hidden even with Moodle themes
            $btn.hide().css('display', 'none').prop('disabled', true);

            // Start review countdown on this approve button if min_review_time is set
            var $approveBtn = $(`.aigrader-approve-btn[data-id="${id}"]`);
            if ($approveBtn.length) {
                startReviewCountdown($approveBtn);
            }

        })
        .fail((xhr) => {
            log('GRADE ajax failure:', xhr.responseText);
            $btn.prop('disabled', false).text(strings.ai_grade);
            showAlert('error', strings.error, strings.error_connection);
        });
    }

    /* ---------------------------------------------------------
     * Minimum review time countdown on approve buttons
     * Locks the button with a visible countdown + reminder message.
     * --------------------------------------------------------- */
    function startReviewCountdown($btn) {
        var seconds = parseInt(config.minReviewTime, 10) || 0;
        if (seconds <= 0) return;

        var originalHtml = $btn.html();
        $btn.prop('disabled', true).addClass('aigrader-approve-locked');
        $btn.data('ag-original-html', originalHtml);

        var remaining = seconds;
        function tick() {
            if (remaining <= 0) {
                $btn.prop('disabled', false).removeClass('aigrader-approve-locked');
                $btn.html(originalHtml);
                return;
            }
            $btn.html(
                '<span class="aigrader-countdown-inner">' +
                '<svg class="ag-icon-sm ag-icon-fixed aigrader-countdown-clock" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">' +
                '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>' +
                '</svg>' +
                '<span class="aigrader-countdown-num">' + remaining + 's</span>' +
                '<span class="aigrader-countdown-msg">Carefully consider student response and AI feedback</span>' +
                '</span>'
            );
            remaining--;
            setTimeout(tick, 1000);
        }
        tick();
    }

    function lockNextApproveButton() {
        var seconds = parseInt(config.minReviewTime, 10) || 0;
        if (seconds <= 0) return;

        var $next = $('.aigrader-approve-btn:not(:disabled):not(.aigrader-approve-locked)').first();
        if ($next.length) {
            startReviewCountdown($next);
        }
    }

    /* ---------------------------------------------------------
     * Approve and save grade to Moodle
     * --------------------------------------------------------- */
    function approveGrade(qubaid, slot, id) {
        const grade100 = parseInt($('#grade-select-' + id).val(), 10);
        const feedbackRaw = $('#feedback-edit-' + id).val();
        const feedbackHtml = formatFeedbackHtml(feedbackRaw, grade100);
        const $btn = $(`.aigrader-approve-btn[data-id="${id}"]`);
        
        // Get autolocked/humanreview flags from the button data attributes
        const autolocked = $btn.data('autolocked') || 0;
        const humanreview = $btn.data('humanreview') || 0;

        $btn.prop('disabled', true)
            .html(`<span class="aigrader-spinner"></span> ${strings.saving}`);

        // Use FormData to handle special characters (emojis) properly
        const formData = new FormData();
        formData.append('action', 'approve');
        formData.append('sesskey', config.sesskey);
        formData.append('cmid', config.cmid);
        formData.append('qubaid', qubaid);
        formData.append('slot', slot);
        formData.append('grade100', grade100);
        formData.append('feedbackhtml', feedbackHtml);
        formData.append('autolocked', autolocked);
        formData.append('humanreview', humanreview);

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
                showAlert('error', strings.error, resp.message);
                return;
            }

            // Show saved state
            const $card = $('#feedback-' + id + ' .aigrader-feedback-card');
            $card.removeClass('aigrader-pending-approval').addClass('aigrader-approved');

            // Replace editable area with static feedback
            // Use actual maxmark from response to calculate proper grade label
            const maxMark = resp.maxmark || 3;
            const mark = resp.mark !== undefined ? resp.mark : Math.round((grade100 / 100) * maxMark * 10) / 10;
            const gradeLabel = Math.round(mark * 10) / 10 + '/' + maxMark;

            $card.html(`
                <div class="aigrader-feedback-header ag-flex-center ag-gap-sm">
                    <svg class="aigrader-feedback-icon ag-icon-sm ag-icon-fixed"
                         xmlns="http://www.w3.org/2000/svg"
                         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>
                    </svg>
                    <span class="aigrader-feedback-label">${strings.feedback}</span>
                    <span class="aigrader-score-badge ag-badge ag-badge-success">${gradeLabel}</span>
                    <span class="aigrader-saved-badge ag-badge ag-badge-success">${strings.saved_to_gradebook}</span>
                </div>
                <div class="aigrader-feedback-text">${feedbackHtml}</div>
            `);

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

    /* ---------------------------------------------------------
     * Update the essay count badge on Grade All button
     * Shows how many essays will be graded (respects search filter)
     * --------------------------------------------------------- */
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

    /* ---------------------------------------------------------
     * Grade ALL essays - parallel processing for speed
     * Only grades visible students (respects search filter)
     * --------------------------------------------------------- */
    function gradeAll() {
        // Only select buttons that are NOT inside a hidden (search-filtered) card
        const buttons = $('.aigrader-card:not(.aigrader-search-hidden) .aigrader-grade-btn:not(:disabled)');

        if (!buttons.length) {
            showAlert('warning', strings.no_essays, strings.no_essays_message);
            return;
        }

        const total = buttons.length;
        let completed = 0;
        let currentIndex = 0;
        const PARALLEL_LIMIT = 2; // Process 2 essays simultaneously
        const DELAY_BETWEEN = 100; // 100ms delay (reduced from 500ms)
        let activeRequests = 0;

        showLoading(true, `${strings.grading} 0/${total}`);

        function gradeNext() {
            // Check if all done
            if (completed >= total) {
                showLoading(false);
                showAlert('success', strings.grading_complete, `${total} essays graded. Please review and approve each.`);
                return;
            }

            // Start new requests up to parallel limit
            while (activeRequests < PARALLEL_LIMIT && currentIndex < total) {
                const idx = currentIndex;
                currentIndex++;
                activeRequests++;

                const $btn = $(buttons[idx]);
                const qubaid = $btn.data('qubaid');
                const slot = $btn.data('slot');

                // Grade this essay
                gradeOneAsync(qubaid, slot, $btn, function() {
                    completed++;
                    activeRequests--;
                    showLoading(true, `${strings.grading} ${completed}/${total}`);
                    
                    // Small delay then try to start next
                    setTimeout(gradeNext, DELAY_BETWEEN);
                });
            }
        }

        // Start initial batch
        gradeNext();
    }

    /* ---------------------------------------------------------
     * gradeOneAsync - grade with callback for batch processing
     * --------------------------------------------------------- */
    function gradeOneAsync(qubaid, slot, $btn, callback) {
        const id = `${qubaid}-${slot}`;

        $btn.prop('disabled', true)
            .html(`<span class="aigrader-spinner"></span> ${strings.grading}`);

        $.post(config.ajaxurl, {
            action: 'suggest',
            sesskey: config.sesskey,
            cmid: config.cmid,
            qubaid: qubaid,
            slot: slot,
            language: config.language || ''
        }, null, 'json')
        .done(resp => {
            log('GRADE ALL response:', resp);

            if (!resp.ok) {
                $btn.prop('disabled', false).text(strings.ai_grade);
                if (callback) callback();
                return;
            }

            // Generate dynamic grade options based on maxmark from response (whole marks only)
            const maxMark = resp.maxmark || 3;
            let gradeOptions = '';
            for (let i = 0; i <= maxMark; i++) {
                const pct = Math.round((i / maxMark) * 100);
                const selected = pct === resp.grade100 ? 'selected' : '';
                gradeOptions += `<option value="${pct}" ${selected}>${i}/${maxMark}</option>`;
            }

            // Format feedback into beautiful cards for preview (same as gradeOne)
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
            const feedbackPreview = formatFeedbackHtml(feedbackRaw, resp.grade100);
            
            // DEBUG: Previous Attempts Feature
            console.log("[AI Grader] Attempt Context:", { attemptNum: resp.attemptnum, maxAttempts: resp.maxattempts, previousAttempt: resp.previousattempt });
            
            // Build attempt context badge (same as gradeOne)
            const attemptNum = resp.attemptnum || 1;
            const maxAttempts = resp.maxattempts || 0;
            const attemptBadge = maxAttempts > 0 
                ? `<span class="aigrader-attempt-badge ag-badge ag-badge-info">Attempt ${attemptNum} of ${maxAttempts}</span>`
                : `<span class="aigrader-attempt-badge ag-badge ag-badge-info">Attempt ${attemptNum}</span>`;
            
            // Build previous attempt section if available
            let previousAttemptHtml = '';
            if (resp.previousattempt && (resp.previousattempt.feedback || resp.previousattempt.grade !== null)) {
                const prevGrade = resp.previousattempt.grade;
                const prevFeedback = resp.previousattempt.feedback || 'No feedback recorded';
                const prevGradeDisplay = prevGrade !== null ? `${prevGrade}%` : 'Not graded';
                const prevGradeBadgeClass = prevGrade >= 100 ? 'ag-badge-success' : (prevGrade >= 50 ? 'ag-badge-warning' : 'ag-badge-error');
                previousAttemptHtml = `
                    <div class="aigrader-previous-attempt">
                        <button type="button" class="aigrader-previous-toggle" data-expanded="false">
                            <svg class="aigrader-chevron ag-icon-xs" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="9 18 15 12 9 6"/>
                            </svg>
                            <span>Previous Attempt</span>
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
                        <div class="aigrader-feedback-header ag-flex-between ag-flex-wrap ag-gap-sm">
                            <div class="ag-flex-center ag-gap-sm ag-flex-wrap">
                                <svg class="aigrader-feedback-icon ag-icon-sm ag-icon-fixed"
                                     xmlns="http://www.w3.org/2000/svg"
                                     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>
                                </svg>
                                <span class="aigrader-feedback-label">${strings.feedback}</span>
                                ${attemptBadge}
                                ${resp.score ? `<span class="aigrader-score-badge ag-badge ag-badge-success">${resp.score}</span>` : ''}
                                <span class="aigrader-pending-badge ag-badge ag-badge-pending">${strings.pending_approval}</span>
                            </div>
                            <div class="aigrader-grade-select ag-flex-center ag-gap-sm">
                                <label>${strings.grade_label}</label>
                                <select class="aigrader-grade-dropdown" id="grade-select-${id}">
                                    ${gradeOptions}
                                </select>
                            </div>
                        </div>
                        ${previousAttemptHtml}
                        <div class="aigrader-feedback-preview" id="feedback-preview-${id}">${feedbackPreview}</div>
                        <div class="aigrader-edit-toggle">
                            <button type="button" class="aigrader-btn aigrader-btn-sm aigrader-btn-ghost aigrader-toggle-edit-btn" data-id="${id}">
                                <svg class="ag-icon-xs" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                </svg>
                                Edit feedback
                            </button>
                        </div>
                        <div class="aigrader-edit-section" id="edit-section-${id}" style="display: none;">
                            <textarea class="aigrader-feedback-edit" id="feedback-edit-${id}">${feedbackRaw}</textarea>
                            <button type="button" class="aigrader-btn aigrader-btn-sm aigrader-btn-secondary aigrader-update-preview-btn" data-id="${id}">
                                Update Preview
                            </button>
                        </div>
                        <div class="aigrader-approval-actions">
                            <button type="button" class="aigrader-btn aigrader-btn-primary aigrader-approve-btn ag-btn-base ag-btn-primary"
                                    data-qubaid="${qubaid}" data-slot="${slot}" data-id="${id}"
                                    data-autolocked="${resp.autolocked ? 1 : 0}" data-humanreview="${resp.humanreview ? 1 : 0}">
                                <svg class="ag-icon-sm ag-icon-fixed" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <polyline points="20 6 9 17 4 12"/>
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
            $('#feedback-edit-' + id).val(feedbackRaw);

            // Update grade label to show pending
            $('#grade-' + id)
                .text(resp.grade)
                .addClass('aigrader-grade-pending');

            // Update credits
            if (resp.credits !== undefined) {
                updateCredits(resp.credits);
            }

            // Hide grade button (same as gradeOne) so updateEssayCount reflects correct count
            $btn.hide().css('display', 'none').prop('disabled', true);

            // Start review countdown on this approve button if min_review_time is set
            var $approveBtn2 = $(`.aigrader-approve-btn[data-id="${id}"]`);
            if ($approveBtn2.length) {
                startReviewCountdown($approveBtn2);
            }

            if (callback) callback();
        })
        .fail(() => {
            $btn.prop('disabled', false).text(strings.ai_grade);
            if (callback) callback();
        });
    }

    /* ---------------------------------------------------------
     * Hide a card after it's been approved (graded)
     * This removes it from the view since we only show ungraded
     * --------------------------------------------------------- */
    function hideGradedCard(id) {
        const $card = $(`.aigrader-card[data-rowid="${id}"]`);
        if ($card.length) {
            $card.fadeOut(500, function() {
                $(this).remove();
                // Update the essay count after removal
                updateEssayCount();
                // Check if no cards left
                if ($('.aigrader-card').length === 0) {
                    // Show "all graded" message
                    $('.aigrader-cards').html(`
                        <div class="aigrader-empty aigrader-all-graded">
                            <svg class="aigrader-empty-icon" xmlns="http://www.w3.org/2000/svg" width="64" height="64" fill="none"
                                    stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                            <h3 class="aigrader-empty-title">${strings.all_graded || 'All essays have been graded'}</h3>
                            <p class="aigrader-empty-message">${strings.all_graded_message || 'There are no ungraded essay responses for this quiz.'}</p>
                        </div>
                    `);
                }
            });
        }
    }

    /* ---------------------------------------------------------
     * Reference documents - list
     * --------------------------------------------------------- */
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

                html += `<div class="aigrader-doc-item" data-docid="${doc.id}">
                    <div class="aigrader-doc-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14 2 14 8 20 8"/>
                        </svg>
                    </div>
                    <div class="aigrader-doc-info">
                        <div class="aigrader-doc-name">${doc.filename}</div>
                        <div class="aigrader-doc-meta">${sizeKB} KB</div>
                    </div>
                    <span class="aigrader-doc-status ${statusClass}">${statusText}</span>
                    <button class="aigrader-doc-delete" data-docid="${doc.id}" title="${strings.document_delete}">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
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

    /* ---------------------------------------------------------
     * Reference documents - upload
     * --------------------------------------------------------- */
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

    /* ---------------------------------------------------------
     * Reference documents - delete
     * --------------------------------------------------------- */
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

    /* ---------------------------------------------------------
     * Extra Instructions - load settings
     * --------------------------------------------------------- */
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

    /* ---------------------------------------------------------
     * Extra Instructions - save settings
     * --------------------------------------------------------- */
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
                $status.html(`<svg class="aigrader-saved-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" stroke="#22c55e" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg> ${strings.instructions_saved}`);
                setTimeout(() => $status.html(''), 3000);
            } else {
                $status.html(`<span class="aigrader-error-text">${resp.message || strings.error}</span>`);
            }
        })
        .fail(() => {
            $btn.prop('disabled', false);
            $status.html(`<span class="aigrader-error-text">${strings.error_connection}</span>`);
        });
    }

    /* ---------------------------------------------------------
     * Load language strings required by module
     * --------------------------------------------------------- */
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
            {key: 'confirm_regrade', component: 'quiz_aigrader'}
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
                strings.confirm_regrade
            ] = result;

            return true;
        });
    }

    /* ---------------------------------------------------------
     * INIT
     * --------------------------------------------------------- */
    function registerHandlers() {
        $(document).on('click', '#aigrader-refresh-btn', fetchCredits);

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
        $(document).on('click', '.aigrader-toggle-edit-btn', function(e) {
            e.preventDefault();
            const id = $(this).data('id');
            const $editSection = $('#edit-section-' + id);
            const $btn = $(this);
            
            if ($editSection.is(':visible')) {
                $editSection.slideUp(200);
                $btn.html(`<svg class="ag-icon-xs" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                </svg> Edit feedback`);
            } else {
                $editSection.slideDown(200);
                $btn.html(`<svg class="ag-icon-xs" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="18 15 12 9 6 15"/>
                </svg> Hide editor`);
                
                // Auto-resize textarea when shown
                const $textarea = $('#feedback-edit-' + id);
                if ($textarea.length) {
                    $textarea[0].style.height = 'auto';
                    $textarea[0].style.height = Math.max(200, $textarea[0].scrollHeight + 20) + 'px';
                }
            }
        });

        // Update preview button handler
        $(document).on('click', '.aigrader-update-preview-btn', function(e) {
            e.preventDefault();
            const id = $(this).data('id');
            const rawText = $('#feedback-edit-' + id).val();
            const newPreview = formatFeedbackHtml(rawText);
            $('#feedback-preview-' + id).html(newPreview);
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

    /* ---------------------------------------------------------
     * Load grading stats for the stats box
     * --------------------------------------------------------- */
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
            if (resp.ok) {
                $('#aigrader-stat-essays').text(resp.totalEssays);
                $('#aigrader-stat-time').text(resp.totalTimeFormatted);
                $('#aigrader-stat-avg').text(resp.avgSecondsPerEssay + 's');
                $('#aigrader-stat-student-time').text(resp.totalStudentTimeFormatted || '0:00:00');

                // Populate filter dropdowns (only once)
                if ($('#aigrader-filter-course option').length <= 1 && resp.filterOptions) {
                    resp.filterOptions.courses.forEach(function(c) {
                        $('#aigrader-filter-course').append('<option value="' + c.id + '">' + c.shortname + '</option>');
                    });
                    resp.filterOptions.graders.forEach(function(g) {
                        $('#aigrader-filter-grader').append('<option value="' + g.id + '">' + g.name + '</option>');
                    });
                }

                // Populate graders table
                var tbody = $('#aigrader-stats-tbody');
                tbody.empty();
                if (!resp.graders || resp.graders.length === 0) {
                    tbody.append('<tr><td colspan="4" class="aigrader-stats-empty">No grading data</td></tr>');
                } else {
                    resp.graders.forEach(function(g) {
                        tbody.append('<tr><td>' + g.name + '</td><td>' + g.essays + '</td><td>' + g.timeFormatted + '</td><td>' + g.avgSecondsPerEssay + 's</td></tr>');
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
                if (cmidMatch) config.cmid = cmidMatch[1];
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
