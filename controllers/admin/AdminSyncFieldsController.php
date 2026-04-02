<?php
if (!defined('_PS_VERSION_')) { exit; }

require_once dirname(__FILE__) . '/AdminSyncBaseController.php';

class AdminSyncFieldsController extends AdminSyncBaseController
{
    public function __construct()
    {
        parent::__construct();
        $this->meta_title = $this->l('SyncMaster Pro — Campos');
    }

    public function initContent()
    {
        parent::initContent();

        $idConn = (int)Tools::getValue('id_connection', 0);
        $action = Tools::getValue('sm_action', '');

        if ($action === 'save') {
            $this->smSave($idConn);
            return;
        }

        $this->smView($idConn);
    }

    private function smView($idConn)
    {
        $connections = Db::getInstance()->executeS(
            'SELECT id_connection, name FROM `' . _DB_PREFIX_ . 'sync_connections`
             WHERE active = 1 ORDER BY name ASC'
        ) ?: [];

        if (!$idConn && !empty($connections)) {
            $idConn = (int)$connections[0]['id_connection'];
        }

        $allFields   = SyncMasterFieldConfig::getAllFields();
        $savedConfig = $idConn ? SyncMasterFieldConfig::getForConnection($idConn) : [];

        $this->context->smarty->assign([
            'connections'        => $connections,
            'id_connection'      => $idConn,
            'all_fields'         => $allFields,
            'saved_config'       => $savedConfig,
            'policy_options'     => [
                'always'       => $this->l('Siempre sobreescribir (master manda)'),
                'if_untouched' => $this->l('Solo si la hija no lo modificó'),
                'never'        => $this->l('Nunca sobreescribir (protegido en hija)'),
            ],
            'price_rule_options' => [
                'none'        => $this->l('Precio original del master'),
                'percent_inc' => $this->l('Incremento porcentual (+%)'),
                'percent_dec' => $this->l('Descuento porcentual (−%)'),
                'fixed_inc'   => $this->l('Incremento fijo (+€)'),
                'fixed_dec'   => $this->l('Descuento fijo (−€)'),
            ],
            'price_fields'       => ['price', 'wholesale_price'],
            'form_action'        => $this->context->link->getAdminLink('AdminSyncFields')
                . '&sm_action=save&id_connection=' . $idConn,
            'link_dashboard'     => $this->context->link->getAdminLink('AdminSyncDashboard'),
        ]);

        $this->renderModuleTemplate('fields_config.tpl');
    }

    private function smSave($idConn)
    {
        if (!$idConn) {
            $this->errors[] = $this->l('Conexión no válida.');
            $this->smView(0);
            return;
        }

        $allFields = SyncMasterFieldConfig::getAllFields();
        $posted    = Tools::getAllValues();

        foreach ($allFields as $group => $groupData) {
            foreach ($groupData['fields'] as $field) {
                $enabled    = isset($posted['field_' . $field]) ? 1 : 0;
                $policy     = (isset($posted['policy_' . $field]) ? $posted['policy_' . $field] : 'always');
                $priceRule  = (isset($posted['price_rule_' . $field]) ? $posted['price_rule_' . $field] : 'none');
                $priceValue = (float)(isset($posted['price_value_' . $field]) ? $posted['price_value_' . $field] : 0);

                if (!in_array($policy, ['always','if_untouched','never'])) { $policy = 'always'; }
                if (!in_array($priceRule, ['none','percent_inc','percent_dec','fixed_inc','fixed_dec'])) { $priceRule = 'none'; }

                SyncMasterFieldConfig::saveField($idConn, $group, $field, [
                    'sync_enabled'     => $enabled,
                    'overwrite_policy' => $policy,
                    'price_rule'       => $priceRule,
                    'price_value'      => $priceValue,
                ]);
            }
        }

        $this->confirmations[] = $this->l('Configuración guardada correctamente.');
        Tools::redirectAdmin(
            $this->context->link->getAdminLink('AdminSyncFields') . '&id_connection=' . $idConn
        );
    }
}
