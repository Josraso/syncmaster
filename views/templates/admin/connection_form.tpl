{**
 * SyncMaster Pro — Formulario de conexión
 *}
<div class="syncmaster-wrap">
    <h2>⇄ {if $is_edit}Editar conexión{else}Añadir tienda hija{/if}</h2>

    {if !empty($errors)}
        {foreach $errors as $err}
            <div class="alert alert-danger"><i class="icon-warning-sign"></i> {$err|escape:'html'}</div>
        {/foreach}
    {/if}

    {* Diagrama de roles — solo en nueva conexión *}
    {if !$is_edit}
    <div class="panel panel-info" style="border-color:#5bc0de">
        <div class="panel-heading" style="background:#5bc0de;color:#fff;font-size:15px">
            <i class="icon-info-sign"></i> ¿Qué vas a conectar?
        </div>
        <div class="panel-body">
            <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap">
                <div style="text-align:center;padding:15px 20px;background:#d9534f;color:#fff;border-radius:8px;min-width:140px">
                    <div style="font-size:28px">🏠</div>
                    <div style="font-weight:bold;font-size:16px">Esta tienda</div>
                    <div style="font-size:13px;margin-top:4px">MASTER</div>
                    <div style="font-size:11px;margin-top:4px;opacity:.85">Envía los cambios</div>
                </div>
                <div style="font-size:32px;color:#999">→</div>
                <div style="text-align:center;padding:15px 20px;background:#5cb85c;color:#fff;border-radius:8px;min-width:140px">
                    <div style="font-size:28px">🏪</div>
                    <div style="font-weight:bold;font-size:16px">Tienda hija</div>
                    <div style="font-size:13px;margin-top:4px">SLAVE</div>
                    <div style="font-size:11px;margin-top:4px;opacity:.85">Recibe los cambios</div>
                </div>
                <div style="flex:1;min-width:200px;border-left:3px solid #eee;padding-left:20px">
                    <p style="margin:0 0 6px"><strong>Esta tienda (master)</strong> enviará productos, stock y precios a la tienda hija (slave).</p>
                    <p style="margin:0">La tienda hija debe tener <strong>SyncMaster Pro instalado</strong> y configurada como <em>Slave</em>.</p>
                </div>
            </div>
        </div>
    </div>

    {* Pasos *}
    <div class="panel">
        <div class="panel-heading"><i class="icon-list-ol"></i> Pasos para conectar las dos tiendas</div>
        <div class="panel-body">
            <div style="display:flex;gap:0;flex-wrap:wrap">
                <div style="flex:1;min-width:180px;padding:12px 18px;border-right:1px solid #eee">
                    <div style="font-size:22px;font-weight:bold;color:#5bc0de">①</div>
                    <div style="font-weight:bold;margin:4px 0">Instala el módulo en la hija</div>
                    <div style="font-size:12px;color:#666">En la tienda hija ve a Módulos → instala SyncMaster Pro → pulsa Configurar → pon el rol en <strong>Slave</strong>.</div>
                </div>
                <div style="flex:1;min-width:180px;padding:12px 18px;border-right:1px solid #eee">
                    <div style="font-size:22px;font-weight:bold;color:#5bc0de">②</div>
                    <div style="font-weight:bold;margin:4px 0">Copia las credenciales</div>
                    <div style="font-size:12px;color:#666">Rellena el formulario de abajo. Copia el <strong>API Key</strong> y <strong>API Secret</strong> que aparecen y pégalos en la configuración de la tienda hija.</div>
                </div>
                <div style="flex:1;min-width:180px;padding:12px 18px">
                    <div style="font-size:22px;font-weight:bold;color:#5bc0de">③</div>
                    <div style="font-weight:bold;margin:4px 0">Guarda y haz el sync inicial</div>
                    <div style="font-size:12px;color:#666">Guarda la conexión, ve a <em>Sync inicial</em> y lanza la sincronización masiva para igualar los catálogos.</div>
                </div>
            </div>
        </div>
    </div>
    {/if}

    {* Formulario *}
    <div class="panel">
        <div class="panel-heading">
            <i class="icon-cog"></i>
            {if $is_edit}Datos de la conexión: <strong>{$connection.name|escape:'html'}</strong>{else}Datos de la tienda hija{/if}
        </div>
        <form method="post" action="{$form_action}">
            {if $is_edit}
                <input type="hidden" name="id_connection" value="{$connection.id_connection}">
            {/if}
            <div class="panel-body">

                <div class="form-group">
                    <label>Nombre para identificar esta conexión *</label>
                    <input type="text" name="name" class="form-control"
                           value="{if $connection.name}{$connection.name|escape:'html'}{/if}"
                           placeholder="Ej: Tienda Francia, Almacén Madrid…" required>
                    <small class="help-block">Solo para que tú la reconozcas. No afecta al funcionamiento.</small>
                </div>

                <div class="form-group">
                    <label>URL de la tienda <span style="color:#d9534f;font-weight:bold">HIJA</span> (la que va a recibir cambios) *</label>
                    <input type="url" name="remote_url" class="form-control"
                           value="{if $connection.remote_url}{$connection.remote_url|escape:'html'}{/if}"
                           placeholder="https://tienda-hija.com" required>
                    <small class="help-block">URL base de la tienda hija, sin barra al final. SyncMaster Pro debe estar instalado ahí.</small>
                </div>

                {if !$is_edit}
                <div class="alert alert-warning" style="margin-top:15px">
                    <i class="icon-copy"></i> <strong>Importante:</strong> Copia el <strong>API Key</strong> y el <strong>API Secret</strong> de abajo y pégalos en la <em>tienda hija</em> (SyncMaster Pro → Configurar → en la sección "Credenciales de esta tienda como Slave").
                </div>
                {/if}

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>API Key — <span style="color:#888;font-weight:normal">credencial de acceso</span> *</label>
                            <div class="input-group">
                                <input type="text" name="api_key" id="sm-api-key" class="form-control sm-mono"
                                       value="{if $connection.api_key}{$connection.api_key|escape:'html'}{elseif $new_credentials.api_key}{$new_credentials.api_key}{/if}"
                                       required>
                                <span class="input-group-btn">
                                    <button type="button" class="btn btn-default sm-copy-btn" data-target="sm-api-key" title="Copiar">
                                        <i class="icon-copy"></i>
                                    </button>
                                </span>
                            </div>
                            <small class="help-block">Copia este valor en la tienda hija.</small>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>API Secret — <span style="color:#888;font-weight:normal">contraseña de acceso</span> *</label>
                            <div class="input-group">
                                <input type="text" name="api_secret" id="sm-api-secret" class="form-control sm-mono"
                                       value="{if $connection.api_secret}{$connection.api_secret|escape:'html'}{elseif $new_credentials.api_secret}{$new_credentials.api_secret}{/if}"
                                       required>
                                <span class="input-group-btn">
                                    <button type="button" class="btn btn-default sm-copy-btn" data-target="sm-api-secret" title="Copiar">
                                        <i class="icon-copy"></i>
                                    </button>
                                </span>
                            </div>
                            <small class="help-block">Copia este valor en la tienda hija.</small>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Modo de IDs de producto</label>
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
                        <strong>Free</strong> (recomendado): la tienda hija puede tener sus propios productos; se usa una tabla de mapeo de IDs.<br>
                        <strong>Shared</strong>: los IDs son idénticos en ambas tiendas. La hija no puede crear productos propios. Solo si instalaste ambas tiendas desde cero juntas.
                    </small>
                </div>

                <hr>
                <h4 style="margin-top:0">Qué se sincroniza</h4>

                <div class="row">
                    <div class="col-md-4">
                        <div class="checkbox"><label>
                            <input type="checkbox" name="sync_stock" value="1"
                                {if !$is_edit || $connection.sync_stock}checked{/if}>
                            Sincronizar stock
                        </label></div>
                        <div class="checkbox"><label>
                            <input type="checkbox" name="sync_prices" value="1"
                                {if !$is_edit || $connection.sync_prices}checked{/if}>
                            Sincronizar precios
                        </label></div>
                        <div class="checkbox"><label>
                            <input type="checkbox" name="sync_images" value="1"
                                {if !$is_edit || $connection.sync_images}checked{/if}>
                            Sincronizar imágenes
                        </label></div>
                        <div class="checkbox"><label>
                            <input type="checkbox" name="active" value="1"
                                {if !$is_edit || $connection.active}checked{/if}>
                            <strong>Conexión activa</strong>
                        </label></div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Productos por lote <small class="text-muted">(sync inicial)</small></label>
                            <input type="number" name="batch_size" class="form-control"
                                   min="10" max="200"
                                   value="{if $connection.batch_size}{$connection.batch_size}{else}50{/if}">
                            <small class="help-block">Cuántos productos se envían de golpe. (10-200)</small>
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

            </div>
            <div class="panel-footer">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="icon-save"></i> Guardar conexión
                </button>
                <a href="{$link_list}" class="btn btn-default btn-lg" style="margin-left:8px">Cancelar</a>
            </div>
        </form>
    </div>
</div>
