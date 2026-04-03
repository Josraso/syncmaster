{**
 * SyncMaster Pro — Lista de conexiones
 *}
<div class="syncmaster-wrap">

    {if $resync_ok}
        <div class="alert alert-success">
            <i class="icon-ok"></i> Solicitud de resync enviada al master correctamente.
            El master procesará el job en segundo plano. Puedes ver el progreso en la página de <strong>Sync inicial</strong>.
        </div>
    {/if}

    <div class="sm-header">
        <div>
            <a href="{$link_dashboard}" class="btn btn-default btn-sm" style="margin-bottom:8px">
                <i class="icon-arrow-left"></i> Panel
            </a>
            <h2 style="margin-top:4px">⇄ Conexiones</h2>
        </div>
        <a href="{$link_add}" class="btn btn-primary">+ Nueva conexión</a>
    </div>

    {if empty($connections)}
        <div class="alert alert-info">
            No hay conexiones configuradas. <a href="{$link_add}">Añade la primera.</a>
        </div>
    {else}
        <div class="panel">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>URL</th>
                        <th>Modo ID</th>
                        <th>Lote</th>
                        <th>Último sync</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                {foreach $connections as $conn}
                    <tr {if !$conn.active}class="sm-row-inactive"{/if}>
                        <td><strong>{$conn.name|escape:'html'}</strong></td>
                        <td><small>{$conn.remote_url|escape:'html'|truncate:40}</small></td>
                        <td><span class="label label-default">{$conn.id_mode}</span></td>
                        <td>{$conn.batch_size} uds</td>
                        <td>
                            {if $conn.last_sync}{$conn.last_sync}{else}<span class="text-muted">Nunca</span>{/if}
                            {if $conn.last_error}
                                <br><small class="text-danger" title="{$conn.last_error|escape:'html'}">
                                    Error: {$conn.last_error|truncate:40|escape:'html'}
                                </small>
                            {/if}
                        </td>
                        <td>
                            {if $conn.active}
                                <span class="label label-success">Activa</span>
                            {else}
                                <span class="label label-warning">Inactiva</span>
                            {/if}
                        </td>
                        <td class="sm-actions">
                            <a href="{$current_url}&sm_action=edit&id_connection={$conn.id_connection}"
                               class="btn btn-default btn-xs" title="Editar">
                                <i class="icon-pencil"></i>
                            </a>
                            <button type="button" class="btn btn-default btn-xs sm-ping-btn"
                                    data-id="{$conn.id_connection}"
                                    data-conn-url="{$current_url}"
                                    title="Test ping">
                                <i class="icon-signal"></i>
                            </button>
                            <a href="{$current_url}&sm_action=toggle&id_connection={$conn.id_connection}"
                               class="btn btn-default btn-xs" title="{if $conn.active}Desactivar{else}Activar{/if}">
                                <i class="icon-{if $conn.active}pause{else}play{/if}"></i>
                            </a>
                            {* Botón de sync según rol *}
                            {if $store_role == 'master' || $store_role == 'both'}
                                {* MASTER: lanza un job local que empuja al slave *}
                                <a href="{$current_url}&sm_action=start_no_images&id_connection={$conn.id_connection}"
                                   class="btn btn-warning btn-xs"
                                   onclick="return confirm('¿Iniciar sync total sin imágenes para «{$conn.name|escape:'html'}»?')"
                                   title="Sync total sin imágenes (master → slave)">
                                    <i class="icon-refresh"></i> Sin imgs
                                </a>
                            {else}
                                {* SLAVE: solicita al master que lance un resync *}
                                <a href="{$current_url}&sm_action=request_resync&id_connection={$conn.id_connection}"
                                   class="btn btn-warning btn-xs"
                                   onclick="return confirm('¿Solicitar resync completo sin imágenes al master «{$conn.name|escape:'html'}»?\nEsto pedirá al master que re-envíe todos los productos.')"
                                   title="Pedir al master que resincronice todo (sin imágenes)">
                                    <i class="icon-refresh"></i> Resync master
                                </a>
                            {/if}
                            <a href="{$current_url}&sm_action=delete&id_connection={$conn.id_connection}"
                               class="btn btn-danger btn-xs"
                               onclick="return confirm('¿Eliminar esta conexión?')" title="Eliminar">
                                <i class="icon-trash"></i>
                            </a>
                        </td>
                    </tr>
                {/foreach}
                </tbody>
            </table>
        </div>
    {/if}
</div>

<div id="sm-ping-result" class="alert" style="display:none;margin-top:10px"></div>

