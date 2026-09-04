/*
 * Pliage de l'arborescence des réseaux, et zébrage des lignes visibles.
 *
 * Amélioration progressive : sans JavaScript, le tableau s'affiche entièrement
 * déplié et le zébrage est assuré par la règle CSS nth-child. Le script prend
 * ensuite la main sur les deux, parce qu'une ligne masquée continue de compter
 * dans nth-child et décalerait l'alternance dès le premier repli.
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'mssub.tree.collapsed';

    function loadCollapsed() {
        try {
            var raw = window.localStorage.getItem(STORAGE_KEY);
            return raw ? new Set(JSON.parse(raw)) : new Set();
        } catch (e) {
            // Navigation privée, stockage refusé : le pliage reste utilisable,
            // il ne survit simplement pas au rechargement.
            return new Set();
        }
    }

    function saveCollapsed(collapsed) {
        try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(Array.from(collapsed)));
        } catch (e) {
            /* sans conséquence */
        }
    }

    function setUp(table) {
        var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
        if (rows.length === 0) {
            return;
        }

        var collapsed = loadCollapsed();
        table.classList.add('js-striped');

        function isHidden(row) {
            // Une ligne est masquée dès qu'un de ses ancêtres est plié.
            var parentId = row.getAttribute('data-parent');
            while (parentId) {
                if (collapsed.has(parentId)) {
                    return true;
                }
                var parent = table.querySelector('tbody tr[data-id="' + parentId + '"]');
                parentId = parent ? parent.getAttribute('data-parent') : null;
            }
            return false;
        }

        function refresh() {
            var alternate = false;

            rows.forEach(function (row) {
                var hidden = isHidden(row);
                row.hidden = hidden;

                row.classList.toggle('is-alt', !hidden && alternate);
                if (!hidden) {
                    alternate = !alternate;
                }

                var toggle = row.querySelector('.tree-toggle[aria-expanded]');
                if (toggle) {
                    var open = !collapsed.has(row.getAttribute('data-id'));
                    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                    toggle.textContent = open ? '▾' : '▸';
                }
            });
        }

        table.addEventListener('click', function (event) {
            var toggle = event.target.closest('.tree-toggle[aria-expanded]');
            if (!toggle) {
                return;
            }

            var id = toggle.closest('tr').getAttribute('data-id');
            if (collapsed.has(id)) {
                collapsed.delete(id);
            } else {
                collapsed.add(id);
            }

            saveCollapsed(collapsed);
            refresh();
        });

        table.treeSetAll = function (collapse) {
            rows.forEach(function (row) {
                var id = row.getAttribute('data-id');
                if (!row.querySelector('.tree-toggle[aria-expanded]')) {
                    return;
                }
                if (collapse) {
                    collapsed.add(id);
                } else {
                    collapsed.delete(id);
                }
            });
            saveCollapsed(collapsed);
            refresh();
        };

        refresh();
    }

    function tableFor(button) {
        // Le premier tableau qui suit le bloc de titre auquel le bouton appartient.
        var node = button.closest('.section-head');
        while (node) {
            node = node.nextElementSibling;
            if (!node) {
                return null;
            }
            if (node.matches('table[data-tree]')) {
                return node;
            }
            var found = node.querySelector('table[data-tree]');
            if (found) {
                return found;
            }
        }
        return null;
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('table[data-tree]').forEach(setUp);

        document.querySelectorAll('[data-tree-all]').forEach(function (button) {
            button.addEventListener('click', function () {
                var table = tableFor(button);
                if (table && table.treeSetAll) {
                    table.treeSetAll(button.getAttribute('data-tree-all') === 'collapse');
                }
            });
        });
    });
})();
