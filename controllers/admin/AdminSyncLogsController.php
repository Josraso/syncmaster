<?php
if (!defined('_PS_VERSION_')) { exit; }

require_once dirname(__FILE__) . '/AdminSyncBaseController.php';

class AdminSyncLogsController extends AdminSyncBaseController
{
    public function __construct()
    {
        parent::__construct();
        $this->meta_title = $this->l('SyncMaster Pro — Registros');
    }

    public function initContent()
    {
        parent::initContent();

        $idConn = (int)Tools::getValue('id_connection', 0);
        $status = Tools::getValue('status', '');
        $action = Tools::getValue('sm_action', '');

        switch ($action) {
            case 'clear':
                Db::getInstance()->execute('TRUNCATE `' . _DB_PREFIX_ . 'sync_log`');
                $this->confirmations[] = $this->l('Registros eliminados.');
                break;
            case 'clear_queue':
                Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'sync_queue` WHERE status = \'failed\'');
                $this->confirmations[] = $this->l('Cola de fallidos limpiada.');
                break;
            case 'retry_all':
                SyncMasterQueue::retryAll();
                $this->confirmations[] = $this->l('Todos los fallidos vuelven a estar pendientes.');
                break;
        }

        $where = '1=1';
        if ($idConn) { $where .= ' AND l.id_connection = ' . (int)$idConn; }
        if ($status && in_array($status, ['success','warning','error'])) {
            $where .= ' AND l.status = \'' . pSQL($status) . '\'';
        }

        $logs = Db::getInstance()->executeS(
            'SELECT l.*, c.name AS connection_name
             FROM `' . _DB_PREFIX_ . 'sync_log` l
             LEFT JOIN `' . _DB_PREFIX_ . 'sync_connections` c ON c.id_connection = l.id_connection
             WHERE ' . $where . ' ORDER BY l.date_add DESC LIMIT 200'
        ) ?: [];

        $connections = Db::getInstance()->executeS(
            'SELECT id_connection, name FROM `' . _DB_PREFIX_ . 'sync_connections` ORDER BY name'
        ) ?: [];

        $queueFailed = Db::getInstance()->executeS(
            'SELECT q.*, c.name AS connection_name
             FROM `' . _DB_PREFIX_ . 'sync_queue` q
             LEFT JOIN `' . _DB_PREFIX_ . 'sync_connections` c ON c.id_connection = q.id_connection
             WHERE q.status = \'failed\'
             ORDER BY q.date_add DESC LIMIT 50'
        ) ?: [];

        $this->context->smarty->assign([
            'logs'           => $logs,
            'connections'    => $connections,
            'queue_stats'    => SyncMasterQueue::getStats(),
            'queue_failed'   => $queueFailed,
            'filter_conn'    => $idConn,
            'filter_status'  => $status,
            'current_url'    => $this->context->link->getAdminLink('AdminSyncLogs'),
            'link_dashboard' => $this->context->link->getAdminLink('AdminSyncDashboard'),
        ]);

        $this->renderModuleTemplate('logs.tpl');
    }
}
