{**
 * SyncMaster Pro — Formulario de conexión
 *}
<div class="syncmaster-wrap">
    <h2>⇄ {if $is_edit}Editar conexión{else}Nueva conexión{/if}</h2>

    <div class="panel">
        <form method="post" action="{$form_action}">
            {if $is_edit}
                <input type="hidden" name="id_connection" value="{$connection.id_connection}">
            {/if}

            <div class="form-group">
                <label>Nombre de la conexión *</label>
                <input type="text" name="name" class="form-control"
                       value="{if $connection.name}{$connection.name|escape:'html'}{/if}"
                       placeholder="Ej: Tienda Francia" required>
                <small class="help-block">Nombre descriptivo para identificar esta tienda hija.</small>
            </div>

            <div class="form-group">
                <label>URL de la tienda hija *</label>
                <input type="url" name="remote_url" class="form-control"
                       value="{if $connection.remote_url}{$connection.remote_url|escape:'html'}{/if}"
                       placeholder="https://hija.mitienda.com" required>
                <small class="help-block">URL base sin barra final. El módulo debe estar instalado ahí.</small>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>API Key *</label>
                        <div class="input-group">
                            <input type="text" name="api_key" class="form-control sm-mono"
                                   value="{if $connection.api_key}{$connection.api_key|escape:'html'}{elseif $new_credentials.api_key}{$new_credentials.api_key}{/if}"
                                   required>
                            {if !$is_edit}
                                <span class="input-group-addon" title="Generada automáticamente">🔑</span>
                            {/if}
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>API Secret *</label>
                        <div class="input-group">
                            <input type="text" name="api_secret" class="form-control sm-mono"
                                   value="{if $connection.api_secret}{$connection.api_secret|escape:'html'}{elseif $new_credentials.api_secret}{$new_credentials.api_secret}{/if}"
                                   required>
                            {if !$is_edit}
                                <span class="input-group-addon" title="Generado automáticamente">🔐</span>
                            {/if}
                        </div>
                        {if !$is_edit}
                            <small class="help-block text-warning">
                                Copia estos valores en la tienda hija antes de guardar.
                            </small>
                        {/if}
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label>Modo de IDs</label>
                <select name="id_mode" class="form-control">
                    {foreach $role_options as $opt}
                        <option value="{$opt.value}"
                            {if $connection.id_mode == $opt.value || (!$connection.id_mode && $opt.value == 'free')}
                                selected
                            {/if}>
                            {$opt.label}
                        </option>
                    {/foreach}
                </select>
                <small class="help-block">
                    <strong>Shared:</strong> los IDs de producto y categoría son los mismos en ambas tiendas. La hija no puede crear sus propios productos.<br>
                    <strong>Free:</strong> la hija puede tener su propio catálogo. Se usa una tabla de mapeo de IDs.
                </small>
            </div>

            <hr>
            <h4>Opciones de sincronización</h4>

            <div class="row">
                <div class="col-md-4">
                    <div class="checkbox">
                        <label>
                            <input type="checkbox" name="sync_stock" value="1"
                                {if !$is_edit || $connection.sync_stock}checked{/if}>
                            Sincronizar stock
                        </label>
                    </div>
                    <div class="checkbox">
                        <label>
                            <input type="checkbox" name="sync_prices" value="1"
                                {if !$is_edit || $connection.sync_prices}checked{/if}>
                            Sincronizar precios
                        </label>
                    </div>
                    <div class="checkbox">
                        <label>
                            <input type="checkbox" name="sync_images" value="1"
                                {if !$is_edit || $connection.sync_images}checked{/if}>
                            Sincronizar imágenes
                        </label>
                    </div>
                    <div class="checkbox">
                        <label>
                            <input type="checkbox" name="active" value="1"
                                {if !$is_edit || $connection.active}checked{/if}>
                            Conexión activa
                        </label>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label>Productos por lote</label>
                        <input type="number" name="batch_size" class="form-control"
                               min="10" max="200"
                               value="{if $connection.batch_size}{$connection.batch_size}{else}50{/if}">
                        <small class="help-block">Para el sync inicial masivo. (10-200)</small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label>Pausa entre lotes (seg)</label>
                        <input type="number" name="batch_delay" class="form-control"
                               min="0" max="30"
                               value="{if isset($connection.batch_delay)}{$connection.batch_delay}{else}1{/if}">
                    </div>
                    <div class="form-group">
                        <label>Timeout HTTP (seg)</label>
                        <input type="number" name="timeout" class="form-control"
                               min="10" max="120"
                               value="{if $connection.timeout}{$connection.timeout}{else}30{/if}">
                    </div>
                </div>
            </div>

            <div class="form-group" style="margin-top:20px">
                <button type="submit" class="btn btn-primary">
                    <i class="icon-save"></i> Guardar conexión
                </button>
                <a href="{$link_list}" class="btn btn-default">Cancelar</a>
            </div>
        </form>
    </div>
</div>
