/* SyncMaster Pro — Admin JS
 * La mayor parte de la lógica JS está inline en las plantillas .tpl
 * para evitar problemas de carga y compatibilidad con PS 1.6/1.7/8/9.
 * Este fichero existe para los assets que deben cargarse globalmente.
 */

(function () {
    'use strict';

    // Confirmación estándar para acciones destructivas
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-sm-confirm]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                var msg = this.dataset.smConfirm || '¿Estás seguro?';
                if (!confirm(msg)) {
                    e.preventDefault();
                    e.stopPropagation();
                }
            });
        });
    });
}());
