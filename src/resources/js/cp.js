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



    class LifecycleField {
        constructor(element) {
            this.element = element;
            this.init();
        }

        init() {
            this.setupDetailsToggle();
            this.setupKeyboardNavigation();
            this.setupAiInsights();
        }


        setupDetailsToggle() {
            const details = this.element.querySelectorAll('.event-details');

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
            const details = this.element.querySelectorAll('.event-details summary');

            details.forEach(summary => {
                summary.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        summary.click();
                    }
                });
            });
        }



        setupAiInsights() {
            const section = this.element.querySelector('.lifecycle-ai-section');
            if (!section) return;

            const btn = section.querySelector('.lifecycle-ai-btn');
            const resultEl = section.querySelector('.lifecycle-ai-result');
            const contentEl = section.querySelector('.lifecycle-ai-content');
            const orderId = section.dataset.orderId;

            if (!btn || !orderId) return;

            // Ensure the button is never disabled by outer form state
            btn.removeAttribute('disabled');

            btn.addEventListener('click', async (e) => {
                e.preventDefault();
                e.stopPropagation();

                if (btn.dataset.loading) return;

                btn.dataset.loading = '1';
                btn.textContent = Craft.t('order-lifecycle', 'Analysing…');
                resultEl.classList.add('hidden');

                try {
                    const response = await Craft.sendActionRequest('POST', 'order-lifecycle/ai/insights', {
                        data: { orderId: orderId },
                    });

                    const data = response.data;

                    if (data.success) {
                        contentEl.innerHTML = parseMarkdown(data.insights);
                        resultEl.classList.remove('hidden');
                        resultEl.classList.remove('lifecycle-ai-error');
                        const metaEl = resultEl.querySelector('.lifecycle-ai-meta');
                        if (metaEl) {
                            metaEl.textContent = Craft.t('order-lifecycle', 'Generated') + ' ' + new Date().toLocaleString();
                        }
                        btn.textContent = Craft.t('order-lifecycle', 'Refresh AI Insights');
                    } else {
                        contentEl.textContent = data.error || 'Failed to get insights.';
                        resultEl.classList.remove('hidden');
                        resultEl.classList.add('lifecycle-ai-error');
                    }
                } catch (err) {
                    const msg = err.response && err.response.data && err.response.data.error
                        ? err.response.data.error
                        : 'Request failed. Please try again.';
                    contentEl.textContent = msg;
                    resultEl.classList.remove('hidden');
                    resultEl.classList.add('lifecycle-ai-error');
                } finally {
                    delete btn.dataset.loading;
                    setBtnText(btn, Craft.t('order-lifecycle', 'AI Insights'));
                }
            });
        }

        logEvent(eventName, data = {}) {
            // Optional: Send analytics or debugging info
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

                if (data.success) {
                    const periodLabel = data.days === 0
                        ? Craft.t('order-lifecycle', 'All time')
                        : Craft.t('order-lifecycle', 'Last {days} days', {days: data.days});

                    let resultEl = this.element.querySelector('.ol-ai-widget-result');
                    if (!resultEl) {
                        const emptyEl = this.element.querySelector('.ol-ai-widget-empty');
                        if (emptyEl) emptyEl.remove();
                        resultEl = document.createElement('div');
                        resultEl.className = 'ol-ai-widget-result';
                        this.element.querySelector('.ol-ai-context-wrap').insertAdjacentElement('beforebegin', resultEl);
                    }
                    resultEl.innerHTML =
                        '<div class="ol-ai-widget-text ol-ai-markdown">' + parseMarkdown(data.insights) + '</div>' +
                        '<div class="ol-ai-widget-meta">' +
                            Craft.t('order-lifecycle', 'Generated') + ' ' + new Date().toLocaleString() +
                            ' &middot; ' + periodLabel +
                            ' <button type="button" class="ol-ai-copy-btn btn small" data-copy="' + escapeAttr(data.insights) + '">' +
                                Craft.t('order-lifecycle', 'Copy') +
                            '</button>' +
                        '</div>';
                    setBtnText(this.btn, Craft.t('order-lifecycle', 'Refresh Insights'));
                } else {
                    this.errorEl.textContent = data.error || 'Failed to get insights.';
                    this.errorEl.classList.remove('hidden');
                }
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
            const text = btn.dataset.copy;
            if (navigator.clipboard && text) {
                navigator.clipboard.writeText(text).then(() => {
                    const orig = btn.textContent;
                    btn.textContent = Craft.t('order-lifecycle', 'Copied!');
                    setTimeout(() => { btn.textContent = orig; }, 2000);
                });
            }
        });
    }

    function initAiWidgets() {
        renderMarkdownElements();
        initCopyButtons();
        document.querySelectorAll('.ol-ai-widget').forEach(el => {
            if (!el.dataset.widgetInit) {
                new AiInsightsWidget(el);
                el.dataset.widgetInit = 'true';
            }
        });
    }

    // Initialize all lifecycle fields on page
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

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }

    // Watch for elements injected after page load (widgets added from dashboard, slideouts, etc.)
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
