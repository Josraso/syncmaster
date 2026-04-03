{**
 * SyncMaster Pro — Sync inicial masivo
 *}
<div class="syncmaster-wrap">
    <a href="{$link_dashboard}" class="btn btn-default btn-sm" style="margin-bottom:8px">
        <i class="icon-arrow-left"></i> Panel
    </a>
    <h2 style="margin-top:4px">⇄ Sincronización inicial masiva</h2>

    {if !empty($running_jobs)}
        <div class="alert alert-warning" style="margin-bottom:12px">
            <strong><i class="icon-warning-sign"></i> Sync en curso</strong> —
            No cierres ni abandones esta página hasta que termine.
            Si sales, el sync se pausará y tendrás que reanudarlo manualmente.
        </div>
    {/if}

    {if !empty($errors)}
        {foreach $errors as $err}
            <div class="alert alert-danger">{$err|escape:'html'}</div>
        {/foreach}
    {/if}

    {* Estadísticas del master *}
    <div class="sm-stats-row">
        <div class="sm-stat">
            <div class="sm-stat-num">{$master_stats.products}</div>
            <div class="sm-stat-label">Productos</div>
        </div>
        <div class="sm-stat">
            <div class="sm-stat-num">{$master_stats.categories}</div>
            <div class="sm-stat-label">Categorías</div>
        </div>
        <div class="sm-stat">
            <div class="sm-stat-num">{$master_stats.images}</div>
            <div class="sm-stat-label">Imágenes</div>
        </div>
    </div>

    {* Lanzar nuevo sync — solo en master *}
    {if $is_master}
    <div class="panel">
        <div class="panel-heading"><i class="icon-refresh"></i> Lanzar sync inicial</div>
        <div class="panel-body">
            <p class="text-muted">
                Envía todo el catálogo (categorías → fabricantes → atributos → características → productos → imágenes)
                a la tienda hija por lotes. Puedes pausarlo y reanudarlo en cualquier momento.
            </p>
            {if empty($connections)}
                <div class="alert alert-warning">No hay conexiones activas. <a href="{$link_dashboard}">Crea una primero.</a></div>
            {else}
                <form method="post" action="{$ajax_url}&sm_action=start">
                    <div class="form-inline">
                        <select name="id_connection" class="form-control" required>
                            <option value="">— Selecciona tienda hija —</option>
                            {foreach $connections as $conn}
                                <option value="{$conn.id_connection}">
                                    {$conn.name|escape:'html'} ({$conn.batch_size} uds/lote)
                                </option>
                            {/foreach}
                        </select>
                        <button type="submit" class="btn btn-primary"
                                onclick="return confirm('¿Iniciar sync inicial? Se enviará TODO el catálogo.')">
                            <i class="icon-refresh"></i> Iniciar sync inicial
                        </button>
                    </div>
                </form>
            {/if}
        </div>
    </div>
    {else}
    <div class="alert alert-info">
        <i class="icon-info-sign"></i>
        <strong>Esta tienda es Slave.</strong>
        El sync inicial lo lanza la tienda Master desde su panel de SyncMaster Pro.
        Esta tienda recibirá los datos automáticamente cuando el master lo inicie.
    </div>
    {/if}

    {* Jobs en curso y recientes *}
    <div class="panel">
        <div class="panel-heading"><i class="icon-list"></i> Jobs de sync inicial</div>
        <div class="panel-body">
            {if empty($jobs)}
                <p class="text-muted">No hay jobs de sync inicial. Lanza uno desde el formulario de arriba.</p>
            {else}
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Conexión</th>
                            <th>Fase actual</th>
                            <th>Progreso</th>
                            <th>Estado</th>
                            <th>Iniciado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    {foreach $jobs as $job}
                        <tr>
                            <td>#{$job.id_job}</td>
                            <td>{$job.connection_name|escape:'html'}</td>
                            <td><span class="label label-default sm-phase-badge">{$job.phase}</span></td>
                            <td>
                                <div class="progress sm-progress" style="margin:0;min-width:120px">
                                    <div class="progress-bar
                                        {if $job.status == 'done'}progress-bar-success
                                        {elseif $job.status == 'failed' || $job.status == 'paused'}progress-bar-warning
                                        {else}progress-bar-info progress-bar-striped active{/if}"
                                        style="width:{$job.progress_pct}%">
                                        {$job.progress_pct}%
                                    </div>
                                </div>
                                <small class="text-muted">
                                    Lote {$job.current_batch}/{$job.total_batches} —
                                    {$job.processed_items} items
                                    {if $job.failed_items > 0}
                                        <span class="text-danger">({$job.failed_items} errores)</span>
                                    {/if}
                                </small>
                            </td>
                            <td>
                                {if $job.status == 'running'}
                                    <span class="label label-info">En curso</span>
                                {elseif $job.status == 'done'}
                                    <span class="label label-success">Completado</span>
                                {elseif $job.status == 'paused'}
                                    <span class="label label-warning">Pausado</span>
                                {elseif $job.status == 'failed'}
                                    <span class="label label-danger">Fallido</span>
                                {else}
                                    <span class="label label-default">{$job.status}</span>
                                {/if}
                            </td>
                            <td><small>{$job.started_at|default:'—'}</small></td>
                            <td>
                                {if $job.status == 'running'}
                                    <button type="button" class="btn btn-xs btn-warning sm-process-btn"
                                            data-job="{$job.id_job}"
                                            data-sync-url="{$ajax_url}">
                                        ▶ Procesar
                                    </button>
                                    <a href="{$ajax_url}&sm_action=pause&id_job={$job.id_job}"
                                       class="btn btn-xs btn-default">⏸ Pausar</a>
                                {elseif $job.status == 'paused'}
                                    <button type="button" class="btn btn-xs btn-primary sm-process-btn"
                                            data-job="{$job.id_job}"
                                            data-sync-url="{$ajax_url}">
                                        ▶ Reanudar
                                    </button>
                                    <a href="{$ajax_url}&sm_action=cancel&id_job={$job.id_job}"
                                       class="btn btn-xs btn-danger"
                                       onclick="return confirm('¿Cancelar el job?')">✕</a>
                                {/if}
                            </td>
                        </tr>
                        {if $job.error_log}
                            <tr>
                                <td colspan="7">
                                    <small class="text-danger">
                                        <strong>Error:</strong> {$job.error_log|escape:'html'}
                                    </small>
                                </td>
                            </tr>
                        {/if}
                    {/foreach}
                    </tbody>
                </table>
            {/if}
        </div>
    </div>
</div>

