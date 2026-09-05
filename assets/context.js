/*
 * Barre de périmètre : soumission au changement.
 *
 * Amélioration progressive. Sans JavaScript, le bouton « Appliquer » fait le
 * travail ; avec, il disparaît et le formulaire part dès qu'une sélection
 * change. Changer d'organisation vide le site, faute de quoi on enverrait un
 * couple incohérent que le serveur devrait rattraper.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('form[data-context]');
        if (!form) {
            return;
        }

        var submit = form.querySelector('[data-context-submit]');
        if (submit) {
            submit.hidden = true;
        }

        var organisation = form.querySelector('#contexte-organisation');
        var site = form.querySelector('#contexte-site');

        if (organisation) {
            organisation.addEventListener('change', function () {
                if (site) {
                    site.value = '';
                }
                form.submit();
            });
        }

        if (site) {
            site.addEventListener('change', function () {
                form.submit();
            });
        }

        var clear = form.querySelector('[data-context-clear]');
        if (clear) {
            clear.addEventListener('click', function (event) {
                event.preventDefault();
                if (organisation) {
                    organisation.value = '';
                }
                if (site) {
                    site.value = '';
                }
                form.submit();
            });
        }
    });
})();
