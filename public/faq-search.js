/**
 * Live filter for the FAQ Search block (`artisanpack/faq-search`).
 *
 * Narrows the Accordion panels in the block's nearest enclosing container
 * that has any, matching the query against each panel's question and answer
 * text (case-insensitive substring). A panel's open or closed state is kept
 * while it's filtered. An Accordions block whose panels are all filtered
 * out is hidden too, and the empty-state message shows the query.
 *
 * Vanilla DOM, idempotent, and re-run on `ap:rebind` like the
 * visual-editor's interactivity script.
 */

(function () {
    'use strict';

    var BOUND = '__apFaqSearchBound';

    function ready(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback, { once: true });
        } else {
            callback();
        }
    }

    function findScope(root) {
        var scope = root.parentElement;

        while (scope && !scope.querySelector('.ap-accordion')) {
            scope = scope.parentElement;
        }

        return scope;
    }

    function bind(root) {
        if (root[BOUND]) {
            return;
        }
        root[BOUND] = true;

        var input = root.querySelector('[data-faq-search-input]');
        var empty = root.querySelector('[data-faq-search-empty]');
        var status = root.querySelector('[data-faq-search-status]');
        var scope = findScope(root);

        if (!input || !scope) {
            return;
        }

        var panels = Array.prototype.slice.call(scope.querySelectorAll('.ap-accordion'));
        var groups = Array.prototype.slice.call(scope.querySelectorAll('.ap-accordions'));
        var texts = panels.map(function (panel) {
            return panel.textContent.toLowerCase();
        });
        var template = root.getAttribute('data-empty-text') || '';
        var announce = 0;

        function apply() {
            var raw = input.value.trim();
            var query = raw.toLowerCase();
            var shown = 0;

            panels.forEach(function (panel, index) {
                var match = query === '' || texts[index].indexOf(query) !== -1;
                panel.hidden = !match;
                shown += match ? 1 : 0;
            });

            groups.forEach(function (group) {
                group.hidden = !group.querySelector('.ap-accordion:not([hidden])');
            });

            var message = shown === 0 ? template.replace('%s', raw) : '';

            if (empty) {
                empty.hidden = shown !== 0;
                empty.textContent = message;
            }

            if (status) {
                window.clearTimeout(announce);
                announce = window.setTimeout(function () {
                    status.textContent = query === ''
                        ? ''
                        : message || shown + (shown === 1 ? ' question' : ' questions') + ' shown';
                }, 400);
            }
        }

        input.addEventListener('input', apply);
        apply();
    }

    function init() {
        var roots = document.querySelectorAll('[data-faq-search]');

        for (var i = 0; i < roots.length; i += 1) {
            bind(roots[i]);
        }
    }

    ready(init);

    window.addEventListener('ap:rebind', init);
})();
