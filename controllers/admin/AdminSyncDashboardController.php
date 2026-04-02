<?php
if (!defined('_PS_VERSION_')) { exit; }

require_once dirname(__FILE__) . '/AdminSyncBaseController.php';

class AdminSyncDashboardController extends AdminSyncBaseController
{
    public function __construct()
    {
        parent::__construct();
        $this->meta_title = $this->l('SyncMaster Pro — Dashboard');
    }

    public function initContent()
    {
        parent::initContent();

        // Procesar cola pendiente si está habilitado el worker admin
        if (Configuration::get('SYNCMASTER_QUEUE_WORKER')) {
            SyncMasterQueue::processQueue(10);
        }

        $connections   = Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'sync_connections` ORDER BY name ASC') ?: [];
        $queueStats    = SyncMasterQueue::getStats();
        $recentErrors  = SyncMasterLogger::getRecent(10, null, 'error');
        $recentSuccess = SyncMasterLogger::getRecent(5,  null, 'success');

        $cronToken = Configuration::get('SYNCMASTER_CRON_TOKEN');
        $cronUrl   = Tools::getShopDomainSsl(true) . __PS_BASE_URI__
            . 'modules/syncmaster/cron/retry_queue.php?token=' . $cronToken;

        // Ping a primeras 5 conexiones activas
        $pingResults = [];
        foreach (array_slice($connections, 0, 5) as $conn) {
            if ($conn['active']) {
                $api = new SyncMasterApi($conn['remote_url'], $conn['api_key'], $conn['api_secret']);
                $pingResults[(int)$conn['id_connection']] = $api->ping();
            }
        }

        $this->context->smarty->assign([
            'syncmaster_connections'    => $connections,
            'syncmaster_queue_stats'    => $queueStats,
            'syncmaster_recent_errors'  => $recentErrors,
            'syncmaster_recent_success' => $recentSuccess,
            'syncmaster_cron_url'       => $cronUrl,
            'syncmaster_role'           => Configuration::get('SYNCMASTER_ROLE'),
            'syncmaster_ping_results'   => $pingResults,
            'syncmaster_ps_version'     => _PS_VERSION_,
            'syncmaster_module_version' => '1.0.1',
            'syncmaster_ajax_url'       => $this->context->link->getAdminLink('AdminSyncDashboard'),
            'link_connections'          => $this->context->link->getAdminLink('AdminSyncConnections'),
            'link_fields'               => $this->context->link->getAdminLink('AdminSyncFields'),
            'link_sync'                 => $this->context->link->getAdminLink('AdminSyncInitialSync'),
            'link_logs'                 => $this->context->link->getAdminLink('AdminSyncLogs'),
        ]);

        $this->renderModuleTemplate('dashboard.tpl');
    }

    /** AJAX: procesar cola manualmente */
    public function ajaxProcessRunQueue()
    {
        $stats = SyncMasterQueue::processQueue(50);
        die(json_encode(['success' => true, 'stats' => $stats]));
    }
}
