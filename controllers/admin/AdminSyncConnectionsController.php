<?php
if (!defined('_PS_VERSION_')) { exit; }

require_once dirname(__FILE__) . '/AdminSyncBaseController.php';
require_once dirname(__FILE__) . '/../../classes/SyncMasterApi.php';

class AdminSyncConnectionsController extends AdminSyncBaseController
{
    public function __construct()
    {
        parent::__construct();
        $this->meta_title = $this->l('SyncMaster Pro — Conexiones');
    }

    public function initContent()
    {
        parent::initContent();

        $action = Tools::getValue('sm_action', 'list');
        $idConn = (int)Tools::getValue('id_connection', 0);

        switch ($action) {
            case 'add':
            case 'edit':   $this->smForm($idConn);      break;
            case 'save':   $this->smSave();          break;
            case 'delete': $this->smDelete($idConn); break;
            case 'toggle': $this->smToggle($idConn); break;
            default:       $this->smList();
        }
    }

    // -------------------------------------------------------------------------

    private function smList()
    {
        $connections = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'sync_connections` ORDER BY name ASC'
        ) ?: [];

        $this->context->smarty->assign([
            'connections' => $connections,
            'link_add'    => $this->context->link->getAdminLink('AdminSyncConnections') . '&sm_action=add',
            'current_url' => $this->context->link->getAdminLink('AdminSyncConnections'),
        ]);
        $this->renderModuleTemplate('connections_list.tpl');
    }

    private function smForm($idConnection = 0)
    {
        $connection = [];
        if ($idConnection) {
            $connection = Db::getInstance()->getRow(
                'SELECT * FROM `' . _DB_PREFIX_ . 'sync_connections` WHERE id_connection = ' . (int)$idConnection
            ) ?: [];
        }

        $this->context->smarty->assign([
            'connection'      => $connection,
            'new_credentials' => !$idConnection ? SyncMasterApi::generateCredentials() : [],
            'is_edit'         => (bool)$idConnection,
            'form_action'     => $this->context->link->getAdminLink('AdminSyncConnections') . '&sm_action=save',
            'link_list'       => $this->context->link->getAdminLink('AdminSyncConnections'),
            'role_options'    => [
                ['value' => 'free',   'label' => $this->l('Free ID — La hija puede tener su propio catálogo')],
                ['value' => 'shared', 'label' => $this->l('Shared ID — Réplica exacta (mismo ID de producto)')],
            ],
        ]);
        $this->renderModuleTemplate('connection_form.tpl');
    }

    private function smSave()
    {
        $idConn    = (int)Tools::getValue('id_connection', 0);
        $now       = date('Y-m-d H:i:s');
        $name      = trim(Tools::getValue('name', ''));
        $remoteUrl = rtrim(trim(Tools::getValue('remote_url', '')), '/');
        $apiKey    = trim(Tools::getValue('api_key', ''));
        $apiSecret = trim(Tools::getValue('api_secret', ''));
        $idMode    = Tools::getValue('id_mode', 'free');

        if (!$name || !$remoteUrl || !$apiKey || !$apiSecret) {
            $this->errors[] = $this->l('Todos los campos obligatorios deben rellenarse.');
            $this->smForm($idConn);
            return;
        }
        if (!Validate::isUrl($remoteUrl)) {
            $this->errors[] = $this->l('La URL de la tienda hija no es válida. Debe incluir https://');
            $this->smForm($idConn);
            return;
        }

        $data = [
            'name'        => pSQL($name),
            'remote_url'  => pSQL($remoteUrl),
            'api_key'     => pSQL($apiKey),
            'api_secret'  => pSQL($apiSecret),
            'id_mode'     => in_array($idMode, ['shared','free']) ? $idMode : 'free',
            'active'      => Tools::getValue('active', 0)      ? 1 : 0,
            'sync_stock'  => Tools::getValue('sync_stock', 0)  ? 1 : 0,
            'sync_prices' => Tools::getValue('sync_prices', 0) ? 1 : 0,
            'sync_images' => Tools::getValue('sync_images', 0) ? 1 : 0,
            'batch_size'  => max(10, min(200, (int)Tools::getValue('batch_size', 50))),
            'batch_delay' => max(0,  min(30,  (int)Tools::getValue('batch_delay', 1))),
            'timeout'     => max(10, min(120, (int)Tools::getValue('timeout', 30))),
            'date_upd'    => $now,
        ];

        if ($idConn) {
            Db::getInstance()->update('sync_connections', $data, 'id_connection = ' . $idConn);
            $this->confirmations[] = $this->l('Conexión actualizada correctamente.');
        } else {
            $data['date_add'] = $now;
            Db::getInstance()->insert('sync_connections', $data);
            $idConn = (int)Db::getInstance()->Insert_ID();
            SyncMasterFieldConfig::initDefaults($idConn);
            $this->confirmations[] = $this->l('Conexión creada. Campos inicializados con valores por defecto.');
        }

        Tools::redirectAdmin($this->context->link->getAdminLink('AdminSyncConnections'));
    }

    private function smDelete($idConnection)
    {
        if (!$idConnection) { return; }
        foreach (['sync_connections','sync_field_config','sync_queue','sync_id_map','sync_initial_job'] as $t) {
            Db::getInstance()->execute(
                'DELETE FROM `' . _DB_PREFIX_ . $t . '` WHERE id_connection = ' . (int)$idConnection
            );
        }
        $this->confirmations[] = $this->l('Conexión eliminada correctamente.');
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminSyncConnections'));
    }

    private function smToggle($idConnection)
    {
        if (!$idConnection) { return; }
        $current = (int)Db::getInstance()->getValue(
            'SELECT active FROM `' . _DB_PREFIX_ . 'sync_connections` WHERE id_connection = ' . (int)$idConnection
        );
        Db::getInstance()->update('sync_connections', [
            'active'   => $current ? 0 : 1,
            'date_upd' => date('Y-m-d H:i:s'),
        ], 'id_connection = ' . (int)$idConnection);
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminSyncConnections'));
    }

    /** AJAX: ping en vivo */
    public function ajaxProcessPing()
    {
        $idConn = (int)Tools::getValue('id_connection', 0);
        if (!$idConn) { die(json_encode(['success' => false, 'error' => 'ID no válido'])); }

        $conn = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'sync_connections` WHERE id_connection = ' . $idConn
        );
        if (!$conn) { die(json_encode(['success' => false, 'error' => 'Conexión no encontrada'])); }

        $api = new SyncMasterApi($conn['remote_url'], $conn['api_key'], $conn['api_secret']);
        die(json_encode($api->ping()));
    }
}
