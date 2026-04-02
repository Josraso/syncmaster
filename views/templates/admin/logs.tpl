{**
 * SyncMaster Pro — Registros
 *}
<div class="syncmaster-wrap">
    <h2>⇄ Registros de sincronización</h2>

    {* Estadísticas de cola *}
    <div class="sm-stats-row">
        <div class="sm-stat sm-stat-pending"><div class="sm-stat-num">{$queue_stats.pending}</div><div class="sm-stat-label">Pendientes</div></div>
        <div class="sm-stat sm-stat-processing"><div class="sm-stat-num">{$queue_stats.processing}</div><div class="sm-stat-label">Procesando</div></div>
        <div class="sm-stat sm-stat-done"><div class="sm-stat-num">{$queue_stats.done}</div><div class="sm-stat-label">Completados</div></div>
        <div class="sm-stat sm-stat-failed"><div class="sm-stat-num">{$queue_stats.failed}</div><div class="sm-stat-label">Fallidos</div></div>
    </div>

    {* Acciones *}
    <div class="panel panel-default">
        <div class="panel-body" style="display:flex;gap:10px;flex-wrap:wrap">
            <a href="{$current_url}&sm_action=retry_all"
               class="btn btn-warning"
               onclick="return confirm('¿Reintentar todos los fallidos?')">
                <i class="icon-refresh"></i> Reintentar todos los fallidos
            </a>
            <a href="{$current_url}&sm_action=clear_queue"
               class="btn btn-default"
               onclick="return confirm('¿Eliminar todos los fallidos de la cola?')">
                <i class="icon-trash"></i> Limpiar cola fallida
            </a>
            <a href="{$current_url}&sm_action=clear"
               class="btn btn-danger"
               onclick="return confirm('¿Eliminar TODOS los registros del log?')">
                <i class="icon-trash"></i> Limpiar log
            </a>
        </div>
    </div>

    {* Filtros *}
    <form method="get" class="form-inline" style="margin-bottom:15px">
        <input type="hidden" name="controller" value="AdminSyncLogs">
        <input type="hidden" name="token" value="{$smarty.get.token|default:''}">
        <select name="id_connection" class="form-control">
            <option value="">Todas las conexiones</option>
            {foreach $connections as $conn}
                <option value="{$conn.id_connection}"
                    {if $filter_conn == $conn.id_connection}selected{/if}>
                    {$conn.name|escape:'html'}
                </option>
            {/foreach}
        </select>
        <select name="status" class="form-control" style="margin-left:5px">
            <option value="">Todos los estados</option>
            <option value="success" {if $filter_status == 'success'}selected{/if}>✓ Éxito</option>
            <option value="warning" {if $filter_status == 'warning'}selected{/if}>⚠ Advertencia</option>
            <option value="error"   {if $filter_status == 'error'}selected{/if}>✗ Error</option>
        </select>
        <button type="submit" class="btn btn-default" style="margin-left:5px">Filtrar</button>
    </form>

    {* Tabla de logs *}
    <div class="panel">
        <div class="panel-heading">
            <i class="icon-file-text"></i> Últimas 200 entradas
        </div>
        <div class="panel-body" style="overflow-x:auto">
            {if empty($logs)}
                <p class="text-muted">No hay registros con los filtros actuales.</p>
            {else}
                <table class="table table-condensed table-striped">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Conexión</th>
                            <th>Entidad</th>
                            <th>ID</th>
                            <th>Acción</th>
                            <th>Estado</th>
                            <th>Duración</th>
                            <th>Mensaje</th>
                        </tr>
                    </thead>
                    <tbody>
                    {foreach $logs as $log}
                        <tr class="sm-log-{$log.status}">
                            <td><small>{$log.date_add}</small></td>
                            <td><small>{$log.connection_name|default:'—'|escape:'html'}</small></td>
                            <td><span class="label label-default">{$log.entity_type}</span></td>
                            <td><small>{$log.entity_id|default:'—'}</small></td>
                            <td><small>{$log.action}</small></td>
                            <td>
                                {if $log.status == 'success'}
                                    <span class="label label-success">✓</span>
                                {elseif $log.status == 'warning'}
                                    <span class="label label-warning">⚠</span>
                                {else}
                                    <span class="label label-danger">✗</span>
                                {/if}
                            </td>
                            <td><small>{if $log.duration_ms}{$log.duration_ms}ms{else}—{/if}</small></td>
                            <td><small class="text-muted">{$log.message|truncate:80|escape:'html'}</small></td>
                        </tr>
                    {/foreach}
                    </tbody>
                </table>
            {/if}
        </div>
    </div>

    {* Cola de fallidos *}
    {if !empty($queue_failed)}
    <div class="panel panel-danger">
        <div class="panel-heading"><i class="icon-warning-sign"></i> Items fallidos en cola</div>
        <div class="panel-body">
            <table class="table table-condensed">
                <thead>
                    <tr><th>Conexión</th><th>Entidad</th><th>ID</th><th>Intentos</th><th>Error</th><th>Fecha</th></tr>
                </thead>
                <tbody>
                {foreach $queue_failed as $item}
                    <tr>
                        <td><small>{$item.connection_name|escape:'html'}</small></td>
                        <td><span class="label label-default">{$item.entity_type}</span></td>
                        <td>{$item.entity_id}</td>
                        <td>{$item.attempts}</td>
                        <td><small class="text-danger">{$item.error_msg|truncate:60|escape:'html'}</small></td>
                        <td><small>{$item.date_add}</small></td>
                    </tr>
                {/foreach}
                </tbody>
            </table>
        </div>
    </div>
    {/if}

</div>
