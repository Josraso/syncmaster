{**
 * SyncMaster Pro — Dashboard
 *}
<div class="syncmaster-wrap">

    {* Cabecera *}
    <div class="sm-header">
        <h2><span class="sm-icon">⇄</span> SyncMaster Pro <small>v{$syncmaster_module_version}</small></h2>
        <div class="sm-role-badge sm-role-{$syncmaster_role}">
            {if $syncmaster_role == 'master'}Master{elseif $syncmaster_role == 'slave'}Slave{else}Master + Slave{/if}
        </div>
    </div>

    {* Configuración de la tienda — PRIMERO *}
    <div class="panel panel-default">
        <div class="panel-heading"><i class="icon-cog"></i> Configuración de la tienda</div>
        <div class="panel-body">
            {if $syncmaster_settings_confirm}
                <div class="alert alert-success">{$syncmaster_settings_confirm|escape:'html'}</div>
            {/if}
            <form method="post" class="form-inline">
                <div class="form-group" style="margin-right:15px">
                    <label style="margin-right:8px"><strong>Rol de esta tienda:</strong></label>
                    <select name="syncmaster_role" class="form-control">
                        <option value="master" {if $syncmaster_role == 'master'}selected{/if}>
                            Master — esta tienda envía cambios a las hijas
                        </option>
                        <option value="slave" {if $syncmaster_role == 'slave'}selected{/if}>
                            Slave — esta tienda recibe cambios del master
                        </option>
                        <option value="both" {if $syncmaster_role == 'both'}selected{/if}>
                            Master + Slave — envía y recibe
                        </option>
                    </select>
                </div>
                <button type="submit" name="submitSyncMasterSettings" class="btn btn-primary">
                    <i class="icon-save"></i> Guardar rol
                </button>
            </form>
            <p class="help-block" style="margin-top:8px;margin-bottom:0">
                <strong>Master:</strong> instala el módulo aquí y añade la tienda hija en "Gestionar conexiones".<br>
                <strong>Slave:</strong> instala el módulo en la tienda hija, copia la API Key y Secret que te dio el master.
            </p>
        </div>
    </div>

    {* Alertas *}
    {if empty($syncmaster_connections)}
    <div class="alert alert-warning">
        <strong>Sin conexiones configuradas.</strong>
        <a href="{$link_connections}" class="btn btn-primary btn-sm" style="margin-left:10px">
            Añadir primera conexión
        </a>
    </div>
    {/if}

    {* Estadísticas de la cola *}
    <div class="sm-stats-row">
        <div class="sm-stat sm-stat-pending">
            <div class="sm-stat-num">{$syncmaster_queue_stats.pending|intval}</div>
            <div class="sm-stat-label">Pendientes</div>
        </div>
        <div class="sm-stat sm-stat-processing">
            <div class="sm-stat-num">{$syncmaster_queue_stats.processing|intval}</div>
            <div class="sm-stat-label">Procesando</div>
        </div>
        <div class="sm-stat sm-stat-done">
            <div class="sm-stat-num">{$syncmaster_queue_stats.done|intval}</div>
            <div class="sm-stat-label">Completados</div>
        </div>
        <div class="sm-stat sm-stat-failed">
            <div class="sm-stat-num">{$syncmaster_queue_stats.failed|intval}</div>
            <div class="sm-stat-label">Fallidos</div>
        </div>
        <div class="sm-stat sm-stat-action">
            <button type="button" class="btn btn-default btn-sm" id="sm-run-queue"
                    data-ajax-url="{$syncmaster_ajax_url}">
                ▶ Procesar cola ahora
            </button>
            <div class="sm-stat-label">Manual</div>
        </div>
    </div>

    <div class="row">

        {* Conexiones *}
        <div class="col-md-6">
            <div class="panel">
                <div class="panel-heading">
                    <i class="icon-link"></i> Conexiones activas
                    <a href="{$link_connections}" class="btn btn-default btn-xs pull-right">Ver todas</a>
                </div>
                <div class="panel-body">
                    {if empty($syncmaster_connections)}
                        <p class="text-muted">No hay conexiones configuradas.</p>
                    {else}
                        <table class="table table-condensed">
                            <thead>
                                <tr>
                                    <th>Nombre</th>
                                    <th>Modo ID</th>
                                    <th>Último sync</th>
                                    <th>Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                            {foreach $syncmaster_connections as $conn}
                                <tr>
                                    <td><strong>{$conn.name|escape:'html'}</strong></td>
                                    <td><span class="label label-default">{$conn.id_mode}</span></td>
                                    <td>
                                        {if $conn.last_sync}
                                            {$conn.last_sync}
                                        {else}
                                            <span class="text-muted">Nunca</span>
                                        {/if}
                                    </td>
                                    <td>
                                        {if isset($syncmaster_ping_results[$conn.id_connection])}
                                            {assign var='ping' value=$syncmaster_ping_results[$conn.id_connection]}
                                            {if $ping.success}
                                                <span class="label label-success">Online ({$ping.latency_ms}ms)</span>
                                            {else}
                                                <span class="label label-danger">Offline</span>
                                            {/if}
                                        {else}
                                            {if $conn.active}
                                                <span class="label label-default">Activa</span>
                                            {else}
                                                <span class="label label-warning">Inactiva</span>
                                            {/if}
                                        {/if}
                                        {if $conn.last_error}
                                            <span class="label label-danger" title="{$conn.last_error|escape:'html'}">!</span>
                                        {/if}
                                    </td>
                                </tr>
                            {/foreach}
                            </tbody>
                        </table>
                    {/if}
                </div>
            </div>
        </div>

        {* Errores recientes *}
        <div class="col-md-6">
            <div class="panel">
                <div class="panel-heading">
                    <i class="icon-warning-sign"></i> Errores recientes (24h)
                    <a href="{$link_logs}" class="btn btn-default btn-xs pull-right">Ver todos</a>
                </div>
                <div class="panel-body">
                    {if empty($syncmaster_recent_errors)}
                        <p class="text-success"><i class="icon-ok"></i> Sin errores recientes.</p>
                    {else}
                        <table class="table table-condensed">
                            <tbody>
                            {foreach $syncmaster_recent_errors as $log}
                                <tr>
                                    <td><small class="text-muted">{$log.date_add}</small></td>
                                    <td><span class="label label-default">{$log.entity_type}</span></td>
                                    <td><small class="text-danger">{$log.message|truncate:80|escape:'html'}</small></td>
                                </tr>
                            {/foreach}
                            </tbody>
                        </table>
                    {/if}
                </div>
            </div>
        </div>

    </div>{* /row *}

    {* Accesos rápidos *}
    <div class="panel">
        <div class="panel-heading"><i class="icon-th-large"></i> Accesos rápidos</div>
        <div class="panel-body">
            <a href="{$link_connections}" class="btn btn-default">
                <i class="icon-link"></i> Gestionar conexiones
            </a>
            <a href="{$link_fields}" class="btn btn-default">
                <i class="icon-list"></i> Configurar campos
            </a>
            <a href="{$link_sync}" class="btn btn-primary">
                <i class="icon-refresh"></i> Sync inicial
            </a>
            <a href="{$link_logs}" class="btn btn-default">
                <i class="icon-file-text"></i> Ver registros
            </a>
            <a href="{$link_reset}" class="btn btn-danger pull-right"
               title="Borra todas las conexiones, cola y configuración del módulo">
                <i class="icon-trash"></i> Reset completo
            </a>
        </div>
    </div>

    {* Cron info *}
    <div class="panel panel-default">
        <div class="panel-heading"><i class="icon-time"></i> Configuración del cron</div>
        <div class="panel-body">
            <p>Para procesar la cola automáticamente, añade esta línea a tu crontab:</p>
            <code>* * * * * php {$syncmaster_ps_root_dir}/modules/syncmaster/cron/retry_queue.php</code>
            <br><br>
            <p>O configura esta URL en el cron de tu panel de hosting:</p>
            <code>{$syncmaster_cron_url|escape:'html'}</code>
        </div>
    </div>

</div>{* /syncmaster-wrap *}

