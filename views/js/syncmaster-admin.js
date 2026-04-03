/* SyncMaster Pro — Admin JS
 * TODOS los handlers de botones están aquí para evitar problemas con
 * Content Security Policy (CSP) en PS 8.x que bloquea scripts inline.
 * Las URLs se pasan mediante atributos data-* en el HTML.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        /* ---- Formulario de conexión: copiar API Key / Secret ---- */
        document.querySelectorAll('.sm-copy-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var targetId = this.dataset.target;
                var input    = document.getElementById(targetId);
                if (!input) { return; }
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(input.value).then(function () {
                        btn.innerHTML = '<i class="icon-ok"></i>';
                        setTimeout(function () { btn.innerHTML = '<i class="icon-copy"></i>'; }, 2000);
                    });
                } else {
                    input.select();
                    document.execCommand('copy');
                    btn.innerHTML = '<i class="icon-ok"></i>';
                    setTimeout(function () { btn.innerHTML = '<i class="icon-copy"></i>'; }, 2000);
                }
            });
        });

        /* ---- Confirmación para acciones destructivas ---- */
        document.querySelectorAll('[data-sm-confirm]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                var msg = this.dataset.smConfirm || '¿Estás seguro?';
                if (!confirm(msg)) {
                    e.preventDefault();
                    e.stopPropagation();
                }
            });
        });

        /* ---- Dashboard: procesar cola ahora ---- */
        var runQueueBtn = document.getElementById('sm-run-queue');
        if (runQueueBtn) {
            runQueueBtn.addEventListener('click', function () {
                var btn     = this;
                var ajaxUrl = btn.dataset.ajaxUrl;
                btn.disabled    = true;
                btn.textContent = 'Procesando...';
                fetch(ajaxUrl + '&sm_ajax=runQueue', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    btn.disabled    = false;
                    btn.textContent = '\u25B6 Procesar cola ahora';
                    alert('Cola procesada: OK=' + data.stats.processed + ' FAIL=' + data.stats.failed);
                    location.reload();
                })
                .catch(function () {
                    btn.disabled    = false;
                    btn.textContent = '\u25B6 Procesar cola ahora';
                    alert('Error al conectar con el servidor. Revisa los registros.');
                });
            });
        }

        /* ---- Conexiones: ping en vivo ---- */
        var pingResult = document.getElementById('sm-ping-result');
        document.querySelectorAll('.sm-ping-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id      = this.dataset.id;
                var connUrl = this.dataset.connUrl;
                if (pingResult) {
                    pingResult.className     = 'alert alert-info';
                    pingResult.style.display = 'block';
                    pingResult.textContent   = 'Comprobando conexi\u00f3n...';
                }
                fetch(connUrl + '&sm_ajax=ping&id_connection=' + id, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!pingResult) { return; }
                    if (data.success) {
                        pingResult.className   = 'alert alert-success';
                        pingResult.textContent = '\u2713 Conexi\u00f3n OK \u2014 PS '
                            + (data.response && data.response.ps_version ? data.response.ps_version : '?')
                            + ' \u2014 ' + data.latency_ms + 'ms';
                    } else {
                        pingResult.className   = 'alert alert-danger';
                        pingResult.textContent = '\u2717 Error: ' + (data.error || 'Sin respuesta');
                    }
                })
                .catch(function (e) {
                    if (!pingResult) { return; }
                    pingResult.className   = 'alert alert-danger';
                    pingResult.textContent = '\u2717 Error de red: ' + e.message;
                });
            });
        });

        /* ---- Sync inicial: procesar lote ---- */
        var smSyncActive = false; // bandera para beforeunload

        window.addEventListener('beforeunload', function (e) {
            if (smSyncActive) {
                var msg = 'La sincronización está en curso. Si sales ahora se interrumpirá y tendrás que reanudarla manualmente.';
                e.preventDefault();
                e.returnValue = msg;
                return msg;
            }
        });

        document.querySelectorAll('.sm-process-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var jobId   = this.dataset.job;
                var syncUrl = this.dataset.syncUrl;
                var thisBtn = this;
                var row     = thisBtn.closest('tr');
                thisBtn.disabled    = true;
                thisBtn.textContent = 'Procesando...';
                smSyncActive = true;

                function processBatch() {
                    fetch(syncUrl + '&sm_ajax=nextBatch&id_job=' + jobId, {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.done) {
                            smSyncActive = false;
                            thisBtn.textContent = data.progress === 100 ? '\u2713 Completado' : '\u23F8 Pausado';
                            setTimeout(function () { location.reload(); }, 1500);
                        } else if (data.paused) {
                            smSyncActive = false;
                            // Error real — dejar el botón de Reanudar visible
                            thisBtn.textContent = '\u23F8 Pausado \u2014 Error';
                            setTimeout(function () { location.reload(); }, 1500);
                        } else {
                            // Lote OK — actualizar UI y lanzar el siguiente automáticamente
                            var bar = row ? row.querySelector('.progress-bar') : null;
                            if (bar) {
                                bar.style.width   = data.progress + '%';
                                bar.textContent   = data.progress + '%';
                            }
                            // Actualizar el badge de fase si cambió
                            if (data.phase && row) {
                                var phaseBadge = row.querySelector('.sm-phase-badge');
                                if (phaseBadge) { phaseBadge.textContent = data.phase; }
                            }
                            thisBtn.textContent = 'Lote ' + data.batch + ' (' + data.progress + '%)...';
                            // Pausa breve entre lotes para no saturar el servidor
                            setTimeout(processBatch, 800);
                        }
                    })
                    .catch(function (e) {
                        thisBtn.disabled    = false;
                        thisBtn.textContent = '\u25B6 Reintentar';
                        alert('Error: ' + e.message);
                    });
                }
                processBatch();
            });
        });

        /* ---- Configuración de campos: marcar/desmarcar todos ---- */
        var checkAll = document.getElementById('sm-check-all');
        if (checkAll) {
            checkAll.addEventListener('click', function () {
                document.querySelectorAll('.sm-field-check').forEach(function (c) { c.checked = true; });
            });
        }
        var uncheckAll = document.getElementById('sm-uncheck-all');
        if (uncheckAll) {
            uncheckAll.addEventListener('click', function () {
                document.querySelectorAll('.sm-field-check').forEach(function (c) { c.checked = false; });
            });
        }

        /* ---- Configuración de campos: toggle por grupo ---- */
        document.querySelectorAll('.sm-group-toggle').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var group      = this.dataset.group;
                var checks     = document.querySelectorAll('.sm-field-row[data-group="' + group + '"] .sm-field-check');
                var allChecked = Array.from(checks).every(function (c) { return c.checked; });
                checks.forEach(function (c) { c.checked = !allChecked; });
            });
        });

        /* ---- Configuración de campos: mostrar/ocultar valor de precio ---- */
        document.querySelectorAll('.sm-price-rule').forEach(function (sel) {
            sel.addEventListener('change', function () {
                var wrap = document.querySelector('.sm-price-value-wrap[data-field="' + this.dataset.field + '"]');
                if (wrap) { wrap.style.display = this.value === 'none' ? 'none' : ''; }
            });
        });

    });
}());
