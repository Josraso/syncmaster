{**
 * SyncMaster Pro — Configuración de campos
 *}
<div class="syncmaster-wrap">
    <h2>⇄ Configuración de campos por conexión</h2>

    {* Selector de conexión *}
    <div class="panel panel-default">
        <div class="panel-body">
            <form method="get" class="form-inline">
                <input type="hidden" name="controller" value="AdminSyncFields">
                <input type="hidden" name="token" value="{$smarty.get.token|default:''}">
                <label style="margin-right:10px">Conexión:</label>
                <select name="id_connection" class="form-control" onchange="this.form.submit()">
                    {foreach $connections as $conn}
                        <option value="{$conn.id_connection}"
                            {if $id_connection == $conn.id_connection}selected{/if}>
                            {$conn.name|escape:'html'}
                        </option>
                    {/foreach}
                </select>
            </form>
        </div>
    </div>

    {if $id_connection}
    <form method="post" action="{$form_action}">

        <div class="sm-fields-legend">
            <span class="sm-legend-item">
                <input type="checkbox" checked disabled> Sincronizar
            </span>
            <span class="sm-legend-sep">·</span>
            <span class="sm-legend-item sm-policy-always">■ Always</span>
            <span class="sm-legend-item sm-policy-if">■ If untouched</span>
            <span class="sm-legend-item sm-policy-never">■ Never</span>
            <button type="button" class="btn btn-xs btn-default pull-right" id="sm-check-all">Marcar todos</button>
            <button type="button" class="btn btn-xs btn-default pull-right" id="sm-uncheck-all" style="margin-right:5px">Desmarcar todos</button>
        </div>

        {foreach $all_fields as $group_key => $group}
        <div class="panel">
            <div class="panel-heading sm-group-heading">
                <strong>{$group.label}</strong>
                <button type="button" class="btn btn-xs btn-default pull-right sm-group-toggle"
                        data-group="{$group_key}">
                    Marcar grupo
                </button>
            </div>
            <div class="panel-body">
                <table class="table table-condensed sm-fields-table">
                    <thead>
                        <tr>
                            <th width="30">Sync</th>
                            <th>Campo</th>
                            <th width="220">Política de sobreescritura</th>
                            <th width="180">Regla de precio</th>
                            <th width="100">Valor</th>
                        </tr>
                    </thead>
                    <tbody>
                    {foreach $group.fields as $field}
                        {assign var=cfg value=$saved_config[$field]|default:null}
                        {assign var=is_price value=in_array($field, ['price','wholesale_price'])}
                        <tr class="sm-field-row" data-group="{$group_key}">
                            <td>
                                <input type="checkbox" name="field_{$field}" value="1"
                                       class="sm-field-check"
                                       {if !$cfg || $cfg.sync_enabled}checked{/if}>
                            </td>
                            <td>
                                <code>{$field}</code>
                            </td>
                            <td>
                                <select name="policy_{$field}" class="form-control input-sm sm-policy-select">
                                    {foreach $policy_options as $val => $label}
                                        <option value="{$val}"
                                            {if $cfg && $cfg.overwrite_policy == $val}selected{/if}>
                                            {$label}
                                        </option>
                                    {/foreach}
                                </select>
                            </td>
                            <td>
                                {if $is_price}
                                    <select name="price_rule_{$field}" class="form-control input-sm sm-price-rule"
                                            data-field="{$field}">
                                        {foreach $price_rule_options as $val => $label}
                                            <option value="{$val}"
                                                {if $cfg && $cfg.price_rule == $val}selected{/if}>
                                                {$label}
                                            </option>
                                        {/foreach}
                                    </select>
                                {else}
                                    <span class="text-muted">—</span>
                                {/if}
                            </td>
                            <td>
                                {if $is_price}
                                    <div class="sm-price-value-wrap" data-field="{$field}"
                                         {if !$cfg || $cfg.price_rule == 'none'}style="display:none"{/if}>
                                        <input type="number" name="price_value_{$field}"
                                               class="form-control input-sm"
                                               min="0" step="0.01"
                                               value="{if $cfg}{$cfg.price_value}{else}0{/if}">
                                    </div>
                                {else}
                                    <span class="text-muted">—</span>
                                {/if}
                            </td>
                        </tr>
                    {/foreach}
                    </tbody>
                </table>
            </div>
        </div>
        {/foreach}

        <div style="margin-top:15px">
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="icon-save"></i> Guardar configuración de campos
            </button>
            <a href="{$link_dashboard}" class="btn btn-default">Cancelar</a>
        </div>

    </form>
    {/if}
</div>

<script>
{literal}
// Mostrar/ocultar valor de precio según regla seleccionada
document.querySelectorAll('.sm-price-rule').forEach(function(sel) {
    sel.addEventListener('change', function() {
        var wrap = document.querySelector('.sm-price-value-wrap[data-field="' + this.dataset.field + '"]');
        if (wrap) wrap.style.display = this.value === 'none' ? 'none' : '';
    });
});

// Marcar/desmarcar todos
document.getElementById('sm-check-all') && document.getElementById('sm-check-all').addEventListener('click', function() {
    document.querySelectorAll('.sm-field-check').forEach(function(c){ c.checked = true; });
});
document.getElementById('sm-uncheck-all') && document.getElementById('sm-uncheck-all').addEventListener('click', function() {
    document.querySelectorAll('.sm-field-check').forEach(function(c){ c.checked = false; });
});

// Marcar grupo
document.querySelectorAll('.sm-group-toggle').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var group = this.dataset.group;
        var checks = document.querySelectorAll('.sm-field-row[data-group="' + group + '"] .sm-field-check');
        var allChecked = Array.from(checks).every(function(c){ return c.checked; });
        checks.forEach(function(c){ c.checked = !allChecked; });
    });
});
{/literal}
</script>
