/*
 * Bulk-Orders — comportements d'interface génériques
 *
 * Filtrage : un conteneur [data-filter-scope] contient
 *   - un champ [data-filter-search] (recherche plein texte sur data-search) ;
 *   - des boutons .chip[data-filter-value] (filtre sur data-status, "" = tout) ;
 *   - des éléments [data-filter-item] ;
 *   - des groupes [data-filter-group] masqués quand ils n'ont plus d'élément visible ;
 *   - un message [data-filter-empty] affiché quand rien ne correspond.
 */
(function () {
    'use strict';

    function normalize(text) {
        return (text || '').toString().toLowerCase()
            .normalize('NFD').replace(/[̀-ͯ]/g, '');
    }

    function applyFilter(scope) {
        var input = scope.querySelector('[data-filter-search]');
        var terms = normalize(input ? input.value : '').split(/\s+/).filter(Boolean);
        var activeChip = scope.querySelector('.chip.active[data-filter-value]');
        var status = activeChip ? activeChip.getAttribute('data-filter-value') : '';
        var visible = 0;

        scope.querySelectorAll('[data-filter-item]').forEach(function (item) {
            var haystack = normalize(item.getAttribute('data-search') || item.textContent);
            var matchText = terms.every(function (t) { return haystack.indexOf(t) !== -1; });
            var statuses = (item.getAttribute('data-status') || '').split(' ');
            var matchStatus = !status || statuses.indexOf(status) !== -1;
            var show = matchText && matchStatus;
            item.classList.toggle('is-hidden', !show);
            if (show) { visible++; }
        });

        scope.querySelectorAll('[data-filter-group]').forEach(function (group) {
            var hasVisible = group.querySelector('[data-filter-item]:not(.is-hidden)');
            group.classList.toggle('is-hidden', !hasVisible);
        });

        // Pendant un filtrage, les panneaux repliés s'ouvrent pour montrer les résultats
        scope.classList.toggle('filtering', terms.length > 0 || !!status);

        scope.querySelectorAll('[data-filter-empty]').forEach(function (el) {
            el.classList.toggle('is-hidden', visible > 0);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-filter-scope]').forEach(function (scope) {
            var input = scope.querySelector('[data-filter-search]');
            if (input) {
                input.addEventListener('input', function () { applyFilter(scope); });
            }
            scope.querySelectorAll('.chip[data-filter-value]').forEach(function (chip) {
                chip.addEventListener('click', function () {
                    scope.querySelectorAll('.chip[data-filter-value]').forEach(function (c) {
                        c.classList.toggle('active', c === chip);
                    });
                    applyFilter(scope);
                });
            });
            applyFilter(scope);
        });
    });

    // Sélecteur d'icône (catégories) : met à jour le <select> associé
    document.addEventListener('click', function (e) {
        var option = e.target.closest('[data-icon-picker] [data-value]');
        if (!option) { return; }
        var picker = option.closest('[data-icon-picker]');
        var select = picker.parentNode.querySelector('select');
        var value = option.getAttribute('data-value');
        select.value = value;
        picker.querySelector('.icon-picker-preview i').className = value || 'fas fa-tag';
        picker.querySelector('.icon-picker-label').textContent = option.getAttribute('data-label');
        picker.querySelectorAll('[data-value]').forEach(function (o) { o.classList.toggle('active', o === option); });
    });

    // Panneaux repliables
    document.addEventListener('click', function (e) {
        var toggle = e.target.closest('.panel-toggle');
        if (toggle && !e.target.closest('a, button, form')) {
            toggle.closest('.panel').classList.toggle('collapsed');
        }
    });

    // Confirmation avant action : <a data-confirm="…"> ou <form data-confirm="…">
    document.addEventListener('click', function (e) {
        var el = e.target.closest('a[data-confirm], button[data-confirm]');
        if (el && !window.confirm(el.getAttribute('data-confirm'))) {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    }, true);
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (form.hasAttribute('data-confirm') && !window.confirm(form.getAttribute('data-confirm'))) {
            e.preventDefault();
        }
    }, true);
})();
