{**
 * SyncMaster Pro — Formulario de conexión (context-aware: master vs slave)
 *}
<div class="syncmaster-wrap">

    {if !empty($errors)}
        {foreach $errors as $err}
            <div class="alert alert-danger"><i class="icon-warning-sign"></i> {$err|escape:'html'}</div>
        {/foreach}
    {/if}

    {* ================================================================ *}
    {* MODO MASTER — añades una tienda HIJA que va a recibir tus cambios *}
    {* ================================================================ *}
    <a href="{$link_list}" class="btn btn-default btn-sm" style="margin-bottom:8px">
        <i class="icon-arrow-left"></i> Volver a conexiones
    </a>

    {if $is_master}

    <h2 style="margin-top:4px">⇄ {if $is_edit}Editar tienda hija: <strong>{$connection.name|escape:'html'}</strong>{else}Conectar una tienda hija (slave){/if}</h2>

    {if !$is_edit}
    <div class="row" style="margin-bottom:20px">
        {* Diagrama *}
        <div class="col-md-12">
            <div style="display:flex;align-items:center;gap:16px;padding:16px;background:#f5f5f5;border-radius:8px;flex-wrap:wrap">
                <div style="text-align:center;padding:14px 22px;background:#d9534f;color:#fff;border-radius:8px;min-width:130px">
                    <div style="font-size:24px">🏠</div>
                    <div style="font-weight:bold">Esta tienda</div>
                    <div style="font-size:12px;background:rgba(0,0,0,.2);border-radius:4px;padding:2px 6px;margin-top:4px">MASTER</div>
                    <div style="font-size:11px;margin-top:4px;opacity:.85">Envía los cambios</div>
                </div>
                <div style="font-size:28px;color:#aaa">➜</div>
                <div style="text-align:center;padding:14px 22px;background:#5cb85c;color:#fff;border-radius:8px;min-width:130px">
                    <div style="font-size:24px">🏪</div>
                    <div style="font-weight:bold">Tienda hija</div>
                    <div style="font-size:12px;background:rgba(0,0,0,.2);border-radius:4px;padding:2px 6px;margin-top:4px">SLAVE</div>
                    <div style="font-size:11px;margin-top:4px;opacity:.85">Recibe los cambios</div>
                </div>
                <div style="flex:1;min-width:200px;padding-left:16px;border-left:3px solid #ddd">
                    <strong>¿Qué tienes que hacer?</strong>
                    <ol style="margin:6px 0 0 0;padding-left:18px;font-size:13px">
                        <li>Instala SyncMaster Pro en la tienda hija y pon el rol en <strong>Slave</strong></li>
                        <li>Rellena la URL de la tienda hija abajo</li>
                        <li>Copia el <strong>API Key</strong> y <strong>API Secret</strong> que se generan y pégalos en la tienda hija</li>
                        <li>Guarda y haz el <em>Sync inicial</em></li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
    {/if}

    <div class="panel">
        <div class="panel-heading">
            <i class="icon-cog"></i>
            {if $is_edit}Datos de la conexión{else}Datos de la tienda hija{/if}
        </div>
        <form method="post" action="{$form_action}">
            {if $is_edit}
                <input type="hidden" name="id_connection" value="{$connection.id_connection}">
            {/if}
            <div class="panel-body">

                <div class="form-group">
                    <label>Nombre interno de esta conexión *</label>
                    <input type="text" name="name" class="form-control"
                           value="{if $connection.name}{$connection.name|escape:'html'}{/if}"
                           placeholder="Ej: Tienda Francia, Almacén Madrid…" required>
                    <small class="help-block">Solo para identificarla aquí. No se muestra en la tienda hija.</small>
                </div>

                <div class="form-group">
                    <label>
                        URL de la tienda <span style="color:#5cb85c;font-weight:bold">HIJA</span>
                        <span style="font-weight:normal;color:#888"> — la que va a RECIBIR tus cambios</span> *
                    </label>
                    <input type="url" name="remote_url" class="form-control"
                           value="{if $connection.remote_url}{$connection.remote_url|escape:'html'}{/if}"
                           placeholder="https://tienda-hija.com" required>
                    <small class="help-block">
                        URL base de la tienda donde están los productos que quieres actualizar. Sin barra al final.<br>
                        SyncMaster Pro debe estar instalado y activo allí.
                    </small>
                </div>

                <div class="alert alert-warning" style="margin:15px 0">
                    <strong><i class="icon-copy"></i> Paso importante:</strong>
                    Copia el <strong>API Key</strong> y <strong>API Secret</strong> de abajo
                    y pégalos en la tienda hija: <em>SyncMaster Pro → Configurar</em>.
                    Sin esto la tienda hija no aceptará las actualizaciones.
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>API Key <small class="text-muted">(cópialo en la tienda hija)</small> *</label>
                            <div class="input-group">
                                <input type="text" name="api_key" id="sm-api-key" class="form-control sm-mono"
                                       value="{if $connection.api_key}{$connection.api_key|escape:'html'}{elseif $new_credentials.api_key}{$new_credentials.api_key}{/if}"
                                       placeholder="Se genera automáticamente" required>
                                <span class="input-group-btn">
                                    <button type="button" class="btn btn-default sm-copy-btn" data-target="sm-api-key" title="Copiar al portapapeles">
                                        <i class="icon-copy"></i>
                                    </button>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>API Secret <small class="text-muted">(cópialo en la tienda hija)</small> *</label>
                            <div class="input-group">
                                <input type="text" name="api_secret" id="sm-api-secret" class="form-control sm-mono"
                                       value="{if $connection.api_secret}{$connection.api_secret|escape:'html'}{elseif $new_credentials.api_secret}{$new_credentials.api_secret}{/if}"
                                       placeholder="Se genera automáticamente" required>
                                <span class="input-group-btn">
                                    <button type="button" class="btn btn-default sm-copy-btn" data-target="sm-api-secret" title="Copiar al portapapeles">
                                        <i class="icon-copy"></i>
                                    </button>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Modo de IDs de producto</label>
                    <select name="id_mode" class="form-control" style="max-width:500px">
                        {foreach $role_options as $opt}
                            <option value="{$opt.value}"
                                {if $connection.id_mode == $opt.value || (!$connection.id_mode && $opt.value == 'free')}selected{/if}>
                                {$opt.label}
                            </option>
                        {/foreach}
                    </select>
                    <small class="help-block">
                        <strong>Free</strong> (recomendado si no sabes): la tienda hija puede tener su propio catálogo, se usa un mapa de IDs.<br>
                        <strong>Shared</strong>: los IDs de producto son idénticos en ambas tiendas. Solo si lo configuraste así desde el principio.
                    </small>
                </div>

                <hr style="margin:20px 0">
                <h4 style="margin-top:0">¿Qué datos se sincronizarán?</h4>
                <div class="row">
                    <div class="col-md-4">
                        <div class="checkbox"><label><input type="checkbox" name="sync_stock" value="1" {if !$is_edit || $connection.sync_stock}checked{/if}> Stock</label></div>
                        <div class="checkbox"><label><input type="checkbox" name="sync_prices" value="1" {if !$is_edit || $connection.sync_prices}checked{/if}> Precios</label></div>
                        <div class="checkbox"><label><input type="checkbox" name="sync_images" value="1" {if !$is_edit || $connection.sync_images}checked{/if}> Imágenes</label></div>
                        <div class="checkbox"><label><input type="checkbox" name="active" value="1" {if !$is_edit || $connection.active}checked{/if}> <strong>Conexión activa</strong></label></div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Productos por lote <small class="text-muted">(10-200)</small></label>
                            <input type="number" name="batch_size" class="form-control" min="10" max="200"
                                   value="{if $connection.batch_size}{$connection.batch_size}{else}50{/if}">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Pausa entre lotes (seg)</label>
                            <input type="number" name="batch_delay" class="form-control" min="0" max="30"
                                   value="{if isset($connection.batch_delay)}{$connection.batch_delay}{else}1{/if}">
                        </div>
                        <div class="form-group">
                            <label>Timeout HTTP (seg)</label>
                            <input type="number" name="timeout" class="form-control" min="10" max="120"
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

    {* ================================================================ *}
    {* MODO SLAVE — añades la tienda MASTER de la que recibirás cambios  *}
    {* ================================================================ *}
    {else}

    <h2>⇄ {if $is_edit}Editar conexión al master: <strong>{$connection.name|escape:'html'}</strong>{else}Conectar con la tienda master{/if}</h2>

    {if !$is_edit}
    <div class="row" style="margin-bottom:20px">
        <div class="col-md-12">
            <div style="display:flex;align-items:center;gap:16px;padding:16px;background:#f5f5f5;border-radius:8px;flex-wrap:wrap">
                <div style="text-align:center;padding:14px 22px;background:#d9534f;color:#fff;border-radius:8px;min-width:130px">
                    <div style="font-size:24px">🏠</div>
                    <div style="font-weight:bold">Tienda master</div>
                    <div style="font-size:12px;background:rgba(0,0,0,.2);border-radius:4px;padding:2px 6px;margin-top:4px">MASTER</div>
                    <div style="font-size:11px;margin-top:4px;opacity:.85">Envía los cambios</div>
                </div>
                <div style="font-size:28px;color:#aaa">➜</div>
                <div style="text-align:center;padding:14px 22px;background:#5cb85c;color:#fff;border-radius:8px;min-width:130px">
                    <div style="font-size:24px">🏪</div>
                    <div style="font-weight:bold">Esta tienda</div>
                    <div style="font-size:12px;background:rgba(0,0,0,.2);border-radius:4px;padding:2px 6px;margin-top:4px">SLAVE</div>
                    <div style="font-size:11px;margin-top:4px;opacity:.85">Recibe los cambios</div>
                </div>
                <div style="flex:1;min-width:200px;padding-left:16px;border-left:3px solid #ddd">
                    <strong>¿Qué tienes que hacer?</strong>
                    <ol style="margin:6px 0 0 0;padding-left:18px;font-size:13px">
                        <li>Ve a la tienda master → SyncMaster Pro → Gestionar conexiones</li>
                        <li>Ahí verás el <strong>API Key</strong> y <strong>API Secret</strong> que generó para esta conexión</li>
                        <li>Cópialos y pégalos en los campos de abajo</li>
                        <li>Rellena la URL de la tienda master y guarda</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
    {/if}

    <div class="panel">
        <div class="panel-heading">
            <i class="icon-cog"></i>
            {if $is_edit}Datos de la conexión{else}Datos de la tienda master{/if}
        </div>
        <form method="post" action="{$form_action}">
            {if $is_edit}
                <input type="hidden" name="id_connection" value="{$connection.id_connection}">
            {/if}
            <div class="panel-body">

                <div class="form-group">
                    <label>Nombre interno de esta conexión *</label>
                    <input type="text" name="name" class="form-control"
                           value="{if $connection.name}{$connection.name|escape:'html'}{/if}"
                           placeholder="Ej: Master principal, Tienda central…" required>
                </div>

                <div class="form-group">
                    <label>
                        URL de la tienda <span style="color:#d9534f;font-weight:bold">MASTER</span>
                        <span style="font-weight:normal;color:#888"> — la que te enviará los cambios</span> *
                    </label>
                    <input type="url" name="remote_url" class="form-control"
                           value="{if $connection.remote_url}{$connection.remote_url|escape:'html'}{/if}"
                           placeholder="https://tienda-master.com" required>
                    <small class="help-block">URL base de la tienda master, sin barra al final.</small>
                </div>

                <div class="alert alert-info" style="margin:15px 0">
                    <strong><i class="icon-info-sign"></i> ¿De dónde saco el API Key y API Secret?</strong><br>
                    Ve a la tienda master → SyncMaster Pro → <em>Gestionar conexiones</em> → edita la conexión que crearon para esta tienda.
                    Copia los valores de <strong>API Key</strong> y <strong>API Secret</strong> y pégalos aquí.
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>API Key <small class="text-muted">(el que te dio la tienda master)</small> *</label>
                            <input type="text" name="api_key" id="sm-api-key" class="form-control sm-mono"
                                   value="{if $connection.api_key}{$connection.api_key|escape:'html'}{/if}"
                                   placeholder="Pega aquí el API Key de la tienda master" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>API Secret <small class="text-muted">(el que te dio la tienda master)</small> *</label>
                            <input type="text" name="api_secret" id="sm-api-secret" class="form-control sm-mono"
                                   value="{if $connection.api_secret}{$connection.api_secret|escape:'html'}{/if}"
                                   placeholder="Pega aquí el API Secret de la tienda master" required>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Modo de IDs de producto</label>
                    <select name="id_mode" class="form-control" style="max-width:500px">
                        {foreach $role_options as $opt}
                            <option value="{$opt.value}"
                                {if $connection.id_mode == $opt.value || (!$connection.id_mode && $opt.value == 'free')}selected{/if}>
                                {$opt.label}
                            </option>
                        {/foreach}
                    </select>
                    <small class="help-block">Debe coincidir con lo que configuró la tienda master para esta conexión.</small>
                </div>

                <hr style="margin:20px 0">
                <h4 style="margin-top:0">Opciones</h4>
                <div class="row">
                    <div class="col-md-4">
                        <div class="checkbox"><label><input type="checkbox" name="active" value="1" {if !$is_edit || $connection.active}checked{/if}> <strong>Conexión activa</strong></label></div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Timeout HTTP (seg)</label>
                            <input type="number" name="timeout" class="form-control" min="10" max="120"
                                   value="{if $connection.timeout}{$connection.timeout}{else}30{/if}">
                        </div>
                    </div>
                    <div class="col-md-4">
                        {* batch_size y batch_delay los controla el master, aquí se ponen en defaults *}
                        <input type="hidden" name="sync_stock"  value="1">
                        <input type="hidden" name="sync_prices" value="1">
                        <input type="hidden" name="sync_images" value="1">
                        <input type="hidden" name="batch_size"  value="50">
                        <input type="hidden" name="batch_delay" value="1">
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

    {/if}{* /is_master *}

</div>
