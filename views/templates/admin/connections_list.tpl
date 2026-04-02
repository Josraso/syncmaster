{**
 * SyncMaster Pro — Lista de conexiones
 *}
<div class="syncmaster-wrap">
    <div class="sm-header">
        <h2>⇄ Conexiones</h2>
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
                                    data-id="{$conn.id_connection}" title="Test ping">
                                <i class="icon-signal"></i>
                            </button>
                            <a href="{$current_url}&sm_action=toggle&id_connection={$conn.id_connection}"
                               class="btn btn-default btn-xs" title="{if $conn.active}Desactivar{else}Activar{/if}">
                                <i class="icon-{if $conn.active}pause{else}play{/if}"></i>
                            </a>
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

<script>
var smConnUrl = '{$current_url}';
{literal}
document.querySelectorAll('.sm-ping-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var id  = this.dataset.id;
        var res = document.getElementById('sm-ping-result');
        res.className = 'alert alert-info';
        res.style.display = 'block';
        res.textContent = 'Comprobando conexión...';

        fetch(smConnUrl + '&sm_action=ping&id_connection=' + id + '&ajax=1&action=ping', {
            method: 'POST',
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                res.className = 'alert alert-success';
                res.textContent = '✓ Conexión OK — PS ' + (data.response && data.response.ps_version || '?')
                    + ' — ' + data.latency_ms + 'ms';
            } else {
                res.className = 'alert alert-danger';
                res.textContent = '✗ Error: ' + (data.error || 'Sin respuesta');
            }
        })
        .catch(function(e) {
            res.className = 'alert alert-danger';
            res.textContent = '✗ Error de red: ' + e.message;
        });
    });
});
{/literal}
</script>
