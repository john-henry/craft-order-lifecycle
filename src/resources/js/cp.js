/**
 * Order Lifecycle Field JavaScript
 */


(function() {
    'use strict';

    function setBtnText(btn, text) {
        const lastTextNode = Array.from(btn.childNodes).reverse()
            .find(n => n.nodeType === Node.TEXT_NODE);
        if (lastTextNode) {
            lastTextNode.textContent = ' ' + text;
        }
    }

    function applyInline(text) {
        return text
            .replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>')
            .replace(/\*([^*\n]+)\*/g, '<em>$1</em>')
            .replace(/`([^`\n]+)`/g, '<code>$1</code>');
    }

    function parseMarkdown(md) {
        const esc = md
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');

        const lines = esc.split('\n');
        const html = [];
        let inList = false;
        let paraLines = [];

        const flushPara = () => {
            if (paraLines.length) {
                html.push('<p>' + paraLines.join('<br>') + '</p>');
                paraLines = [];
            }
        };

        for (const line of lines) {
            const trimmed = line.trim();

            if (!trimmed) {
                flushPara();
                if (inList) { html.push('</ul>'); inList = false; }
                continue;
            }

            const headMatch = trimmed.match(/^(#{1,3}) (.+)$/);
            if (headMatch) {
                flushPara();
                if (inList) { html.push('</ul>'); inList = false; }
                const level = headMatch[1].length;
                html.push('<h' + level + '>' + applyInline(headMatch[2]) + '</h' + level + '>');
                continue;
            }

            const listMatch = trimmed.match(/^[-*] (.+)$/);
            if (listMatch) {
                flushPara();
                if (!inList) { html.push('<ul>'); inList = true; }
                html.push('<li>' + applyInline(listMatch[1]) + '</li>');
                continue;
            }

            if (inList) { html.push('</ul>'); inList = false; }
            paraLines.push(applyInline(trimmed));
        }

        flushPara();
        if (inList) html.push('</ul>');

        return html.join('\n');
    }

    function escapeAttr(str) {
        return str
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    // mirrors AiInsightsService::decodeStructuredInsights() - null means
    // fall back to narrative markdown
    function tryParseStructuredInsights(raw) {
        if (!raw) return null;

        const clean = raw.trim()
            .replace(/^```(?:json)?\s*/i, '')
            .replace(/```\s*$/, '')
            .trim();

        let data;
        try {
            data = JSON.parse(clean);
        } catch (e) {
            return null;
        }

        return (data && Array.isArray(data.items)) ? data : null;
    }

    // Mirrors _includes/ai-insight-structured.twig - keep both in sync.
    function renderStructuredInsights(data) {
        const parts = ['<div class="lifecycle-analysis">'];

        if (data.priorityAction) {
            parts.push(
                '<div class="lifecycle-analysis-priority">' +
                    '<svg class="lifecycle-analysis-priority-icon" width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">' +
                        '<path fill-rule="evenodd" d="M8 15A7 7 0 1 0 8 1a7 7 0 0 0 0 14Zm0-10.75a.75.75 0 0 1 .75.75v4a.75.75 0 0 1-1.5 0V5a.75.75 0 0 1 .75-.75ZM8 11.5a.875.875 0 1 0 0-1.75.875.875 0 0 0 0 1.75Z" clip-rule="evenodd" />' +
                    '</svg>' +
                    '<span><strong>' + Craft.t('order-lifecycle', 'Priority action') + ':</strong> ' + escapeAttr(data.priorityAction) + '</span>' +
                '</div>'
            );
        }

        parts.push('<div class="lifecycle-analysis-items">');

        (Array.isArray(data.items) ? data.items : []).forEach((item) => {
            const type = item.type === 'action' ? 'action' : (item.type === 'good' ? 'good' : 'info');
            const bullets = Array.isArray(item.bullets) ? item.bullets : [];

            parts.push(
                '<div class="lifecycle-analysis-item">' +
                    '<span class="lifecycle-insight-badge lifecycle-insight-badge--' + type + '">' + type.toUpperCase() + '</span>' +
                    '<div class="lifecycle-analysis-item-body">' +
                        '<span class="lifecycle-analysis-item-title">' + escapeAttr(item.title || '') + '</span> ' +
                        escapeAttr(item.description || '') +
                        (bullets.length
                            ? '<ul class="lifecycle-analysis-item-bullets">' +
                                bullets.map((bullet) => '<li>' + escapeAttr(bullet) + '</li>').join('') +
                              '</ul>'
                            : '') +
                    '</div>' +
                '</div>'
            );
        });

        parts.push('</div>', '</div>');

        return parts.join('');
    }



    class LifecycleField {
        constructor(element) {
            this.element = element;
            this.init();
        }

        init() {
            // wrapped separately so one throwing doesn't stop the rest from wiring up
            const steps = [
                () => this.setupDetailsToggle(),
                () => this.setupKeyboardNavigation(),
                () => this.setupAiInsights(),
                () => this.setupFilterPills(),
                () => this.setupSortToggle(),
                () => this.setupSnapshotToggles(),
                () => this.updateDateLabels(),
            ];

            steps.forEach((step) => {
                try {
                    step();
                } catch (e) {
                    if (window.console && window.console.error) {
                        console.error('Order Lifecycle field init step failed:', e);
                    }
                }
            });
        }


        setupDetailsToggle() {
            const details = this.element.querySelectorAll('.lifecycle-event-details');

            details.forEach(detail => {
                const summary = detail.querySelector('summary');

                if (summary) {
                    summary.addEventListener('click', () => {
                        // Animation handled by CSS
                        this.logEvent('snapshot_toggled', {
                            open: !detail.open
                        });
                    });
                }
            });
        }

        setupKeyboardNavigation() {
            const details = this.element.querySelectorAll('.lifecycle-event-details summary');

            details.forEach(summary => {
                summary.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        summary.click();
                    }
                });
            });
        }

        setupFilterPills() {
            const pills = this.element.querySelectorAll('.lifecycle-filter-pill');
            if (!pills.length) return;

            const events = this.element.querySelectorAll('.lifecycle-event');

            pills.forEach((pill) => {
                // Ensure the button is never disabled by outer form state
                pill.removeAttribute('disabled');

                pill.addEventListener('click', () => {
                    pills.forEach((p) => {
                        p.classList.remove('active');
                        p.setAttribute('aria-pressed', 'false');
                    });
                    pill.classList.add('active');
                    pill.setAttribute('aria-pressed', 'true');

                    const filter = pill.dataset.pill;
                    let visibleCount = 0;
                    events.forEach((eventEl) => {
                        const matches = filter === 'all' || eventEl.dataset.pill === filter;
                        eventEl.classList.toggle('hidden', !matches);
                        if (matches) visibleCount++;
                    });

                    this._announce(
                        filter === 'all'
                            ? Craft.t('order-lifecycle', 'Showing all {count} events', {count: events.length})
                            : Craft.t('order-lifecycle', 'Showing {visible} of {total} events', {
                                visible: visibleCount,
                                total: events.length,
                            })
                    );
                });
            });
        }

        setupSortToggle() {
            const toggle = this.element.querySelector('.lifecycle-sort-toggle');
            const timeline = this.element.querySelector('.lifecycle-timeline');
            if (!toggle || !timeline) return;

            // Ensure the button is never disabled by outer form state
            toggle.removeAttribute('disabled');

            const label = toggle.querySelector('.lifecycle-sort-label');

            toggle.addEventListener('click', () => {
                const isDesc = toggle.dataset.sort !== 'asc';
                toggle.dataset.sort = isDesc ? 'asc' : 'desc';

                if (label) {
                    label.textContent = isDesc
                        ? Craft.t('order-lifecycle', 'Oldest first')
                        : Craft.t('order-lifecycle', 'Newest first');
                }

                Array.from(timeline.children).reverse().forEach((eventEl) => {
                    timeline.appendChild(eventEl);
                });

                this.updateDateLabels();

                this._announce(
                    isDesc
                        ? Craft.t('order-lifecycle', 'Sorted oldest first')
                        : Craft.t('order-lifecycle', 'Sorted newest first')
                );
            });
        }

        // visually hidden, but aria-live announces it - filtering/sorting
        // doesn't move focus or give screen readers anything else to go on
        _announce(message) {
            const status = this.element.querySelector('.lifecycle-timeline-status');
            if (status) {
                status.textContent = message;
            }
        }

        // date only shows once, on the row where it changes, and only if the
        // timeline spans more than a day - re-run after sorting since that
        // depends on display order
        updateDateLabels() {
            const timeline = this.element.querySelector('.lifecycle-timeline');
            if (!timeline) return;

            const events = Array.from(timeline.querySelectorAll('.lifecycle-event'));
            if (!events.length) return;

            const spansMultipleDays = new Set(events.map((el) => el.dataset.date)).size > 1;

            let previousDate = null;
            events.forEach((eventEl) => {
                const dateEl = eventEl.querySelector('.lifecycle-event-date');
                if (!dateEl) return;

                const date = eventEl.dataset.date;
                dateEl.classList.toggle('hidden', !spansMultipleDays || date === previousDate);
                previousDate = date;
            });
        }

        setupSnapshotToggles() {
            const toggles = this.element.querySelectorAll('.lifecycle-snapshot-toggle');

            toggles.forEach((toggle) => {
                const container = toggle.closest('.lifecycle-event-body');
                const content = container ? container.querySelector('.snapshot-content') : null;
                if (!content) return;

                // Ensure the button is never disabled by outer form state
                toggle.removeAttribute('disabled');

                const textEl = toggle.querySelector('.lifecycle-snapshot-toggle-text');

                toggle.addEventListener('click', () => {
                    const isOpen = toggle.getAttribute('aria-expanded') === 'true';
                    toggle.setAttribute('aria-expanded', String(!isOpen));
                    content.classList.toggle('hidden', isOpen);

                    if (textEl) {
                        textEl.textContent = isOpen
                            ? Craft.t('order-lifecycle', 'View full snapshot')
                            : Craft.t('order-lifecycle', 'Hide full snapshot');
                    }
                });
            });
        }

        setupAiInsights() {
            const section = this.element.querySelector('.lifecycle-ai-section');
            if (!section) return;

            const card = section.querySelector('.lifecycle-analysis-card');
            const btn = section.querySelector('.lifecycle-ai-btn');
            const resultEl = section.querySelector('.lifecycle-ai-result');
            const contentEl = section.querySelector('.lifecycle-ai-content');
            const metaEl = section.querySelector('.lifecycle-ai-meta');
            const statusEl = section.querySelector('.lifecycle-analysis-status');
            const subtitleEl = section.querySelector('.lifecycle-analysis-subtitle');
            const orderId = section.dataset.orderId;

            if (!btn || !orderId) return;

            // Ensure the button is never disabled by outer form state
            btn.removeAttribute('disabled');

            btn.addEventListener('click', async (e) => {
                e.preventDefault();
                e.stopPropagation();

                if (btn.dataset.loading) return;

                const hadPriorInsights = !resultEl.classList.contains('hidden') && !resultEl.classList.contains('lifecycle-ai-error');

                btn.dataset.loading = '1';
                btn.disabled = true;
                setBtnText(btn, Craft.t('order-lifecycle', 'Analyzing order…'));
                resultEl.classList.remove('hidden');
                resultEl.classList.remove('lifecycle-ai-error');
                contentEl.innerHTML =
                    '<div class="lifecycle-ai-loading-header">' +
                        '<span class="lifecycle-ai-spinner" aria-hidden="true"></span>' +
                        '<span>' + Craft.t('order-lifecycle', 'Analyzing order…') + '</span>' +
                    '</div>' +
                    '<div class="lifecycle-ai-skeleton">' +
                        '<div class="lifecycle-ai-skeleton-bar" style="width: 100%"></div>' +
                        '<div class="lifecycle-ai-skeleton-bar" style="width: 65%"></div>' +
                        '<div class="lifecycle-ai-skeleton-bar" style="width: 80%"></div>' +
                    '</div>';

                let succeeded = false;

                try {
                    const response = await Craft.sendActionRequest('POST', 'order-lifecycle/ai/insights', {
                        data: { orderId: orderId },
                    });

                    const data = response.data;

                    if (data.success) {
                        succeeded = true;
                        const structured = tryParseStructuredInsights(data.insights);
                        contentEl.innerHTML = structured
                            ? renderStructuredInsights(structured)
                            : parseMarkdown(data.insights);
                        resultEl.classList.remove('hidden');
                        resultEl.classList.remove('lifecycle-ai-error');
                        if (card) card.dataset.state = 'ready';
                        if (statusEl) statusEl.classList.remove('hidden');
                        if (subtitleEl) subtitleEl.classList.add('hidden');
                        if (metaEl) {
                            metaEl.classList.remove('hidden');
                            metaEl.textContent = Craft.t('order-lifecycle', 'Generated') + ' ' + new Date().toLocaleString();
                        }
                        const summaryEl = resultEl.querySelector('.lifecycle-analysis-summary');
                        if (summaryEl && data.summary) {
                            summaryEl.textContent = data.summary;
                        }
                    } else {
                        contentEl.textContent = data.error || Craft.t('order-lifecycle', 'Failed to get insights.');
                        resultEl.classList.remove('hidden');
                        resultEl.classList.add('lifecycle-ai-error');
                    }
                } catch (err) {
                    const msg = err.response && err.response.data && err.response.data.error
                        ? err.response.data.error
                        : Craft.t('order-lifecycle', 'Request failed. Please try again.');
                    contentEl.textContent = msg;
                    resultEl.classList.remove('hidden');
                    resultEl.classList.add('lifecycle-ai-error');
                } finally {
                    delete btn.dataset.loading;
                    btn.disabled = false;
                    setBtnText(btn, (succeeded || hadPriorInsights)
                        ? Craft.t('order-lifecycle', 'Refresh')
                        : Craft.t('order-lifecycle', 'Generate insights'));
                }
            });
        }

        logEvent(eventName, data = {}) {
            if (window.console && window.console.debug) {
                console.debug(`Lifecycle Field: ${eventName}`, data);
            }
        }
    }

    class AiInsightsWidget {
        constructor(element) {
            this.element = element;
            this.btn = element.querySelector('.ol-ai-widget-btn');
            this.spinner = element.querySelector('.ol-ai-widget-spinner');
            this.errorEl = element.querySelector('.ol-ai-widget-error');
            this.defaultDays = parseInt(element.dataset.days || '30', 10);

            if (this.btn) {
                this.btn.addEventListener('click', () => this.generate());
            }
        }

        async generate() {
            if (this.btn.dataset.loading) return;

            this.btn.dataset.loading = '1';
            this.btn.disabled = true;
            this.spinner.classList.remove('hidden');
            this.errorEl.classList.add('hidden');

            const days = parseInt(this.element.dataset.days || this.defaultDays, 10);
            const contextEl = this.element.querySelector('.ol-ai-context-notes');
            const context = contextEl ? contextEl.value.trim() : '';

            try {
                const response = await Craft.sendActionRequest('POST', 'order-lifecycle/ai/store-insights', {
                    data: { days, context },
                });

                const data = response.data;

                if (!data.success) {
                    this.errorEl.textContent = data.error || 'Failed to generate insights.';
                    this.errorEl.classList.remove('hidden');
                    return;
                }

                this.renderResult(data.insights, data.days);
            } catch (err) {
                const msg = err.response && err.response.data && err.response.data.error
                    ? err.response.data.error
                    : 'Request failed. Please try again.';
                this.errorEl.textContent = msg;
                this.errorEl.classList.remove('hidden');
            } finally {
                delete this.btn.dataset.loading;
                this.btn.disabled = false;
                this.spinner.classList.add('hidden');
            }
        }

        renderResult(insights, days) {
            const periodLabel = days === 0
                ? Craft.t('order-lifecycle', 'All time')
                : Craft.t('order-lifecycle', 'Last {days} days', {days: days});

            let resultEl = this.element.querySelector('.ol-ai-widget-result');
            if (!resultEl) {
                const emptyEl = this.element.querySelector('.ol-ai-widget-empty');
                if (emptyEl) emptyEl.remove();
                resultEl = document.createElement('div');
                resultEl.className = 'ol-ai-widget-result';
                this.element.querySelector('.ol-ai-context-wrap').insertAdjacentElement('beforebegin', resultEl);
            }
            resultEl.setAttribute('aria-live', 'polite');
            resultEl.innerHTML =
                '<div class="ol-ai-widget-text ol-ai-markdown">' + parseMarkdown(insights) + '</div>' +
                '<div class="ol-ai-widget-meta">' +
                    Craft.t('order-lifecycle', 'Generated') + ' ' + new Date().toLocaleString() +
                    ' &middot; ' + periodLabel +
                    ' <button type="button" class="ol-ai-copy-btn btn small" data-copy="' + escapeAttr(insights) + '">' +
                        Craft.t('order-lifecycle', 'Copy') +
                    '</button>' +
                '</div>';
            setBtnText(this.btn, Craft.t('order-lifecycle', 'Refresh Insights'));

            // Matches the per-order "Order analysis" card: dashed border
            // until insights exist, solid once they do.
            this.element.dataset.state = 'ready';
        }
    }

    function renderMarkdownElements() {
        document.querySelectorAll('.ol-ai-markdown[data-raw]').forEach(el => {
            el.innerHTML = parseMarkdown(el.dataset.raw);
        });
    }

    function initCopyButtons() {
        if (initCopyButtons._bound) return;
        initCopyButtons._bound = true;
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.ol-ai-copy-btn');
            if (!btn) return;
            // copy the rendered, readable text rather than the raw markdown source
            const result = btn.closest('.ol-ai-widget-result');
            const rendered = result ? result.querySelector('.ol-ai-widget-text') : null;
            const text = rendered ? rendered.innerText.trim() : (btn.dataset.copy || '');
            if (navigator.clipboard && text) {
                navigator.clipboard.writeText(text).then(() => {
                    const orig = btn.textContent;
                    btn.textContent = Craft.t('order-lifecycle', 'Copied!');
                    setTimeout(() => { btn.textContent = orig; }, 2000);
                });
            }
        });
    }

    function initPeriodSelects() {
        if (initPeriodSelects._bound) return;
        initPeriodSelects._bound = true;
        document.addEventListener('change', (e) => {
            const select = e.target.closest('.ol-ai-dashboard-period select');
            if (!select) return;
            const widget = select.closest('.ol-ai-widget');
            if (widget) widget.dataset.days = select.value;
        });
    }

    function initAiWidgets() {
        renderMarkdownElements();
        initCopyButtons();
        initPeriodSelects();
        document.querySelectorAll('.ol-ai-widget').forEach(el => {
            if (!el.dataset.widgetInit) {
                new AiInsightsWidget(el);
                el.dataset.widgetInit = 'true';
            }
        });
    }

    function initLifecycleFields() {
        const fields = document.querySelectorAll('.order-lifecycle-field');
        fields.forEach(field => {
            if (!field.dataset.initialized) {
                new LifecycleField(field);
                field.dataset.initialized = 'true';
            }
        });
    }

    function initAll() {
        initLifecycleFields();
        initAiWidgets();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }

    // covers widgets/fields added after load - new dashboard widgets, slideouts, etc.
    if (typeof MutationObserver !== 'undefined') {
        new MutationObserver(function(mutations) {
            var needsInit = false;
            mutations.forEach(function(mutation) {
                mutation.addedNodes.forEach(function(node) {
                    if (node.nodeType === 1 && (
                        node.classList.contains('order-lifecycle-field') ||
                        node.classList.contains('ol-ai-widget') ||
                        node.querySelector('.order-lifecycle-field, .ol-ai-widget')
                    )) {
                        needsInit = true;
                    }
                });
            });
            if (needsInit) initAll();
        }).observe(document.body, { childList: true, subtree: true });
    }

    window.LifecycleField = LifecycleField;
    window.initLifecycleFields = initLifecycleFields;
})();
