<?php
if (!defined('_PS_VERSION_')) { exit; }

require_once dirname(__FILE__) . '/AdminSyncBaseController.php';

$_smDir = dirname(__FILE__) . '/../../classes/';
foreach (['SyncMasterSerializer','SyncMasterApi','SyncMasterInitialJob','SyncMasterQueue'] as $_c) {
    if (!class_exists($_c) && file_exists($_smDir . $_c . '.php')) require_once $_smDir . $_c . '.php';
}
unset($_smDir, $_c);

class AdminSyncInitialSyncController extends AdminSyncBaseController
{
    public function __construct()
    {
        parent::__construct();
        $this->meta_title = $this->l('SyncMaster Pro — Sync inicial');
    }

    public function initContent()
    {
        parent::initContent();

        $action = Tools::getValue('sm_action', '');
        $idConn = (int)Tools::getValue('id_connection', 0);
        $idJob  = (int)Tools::getValue('id_job', 0);

        switch ($action) {
            case 'start':
                $this->smStart($idConn);
                return;
            case 'pause':
                SyncMasterInitialJob::pauseJob($idJob);
                $this->confirmations[] = $this->l('Sync pausado.');
                break;
            case 'cancel':
                SyncMasterInitialJob::cancelJob($idJob);
                $this->confirmations[] = $this->l('Sync cancelado.');
                break;
            case 'resume':
                Db::getInstance()->update('sync_initial_job', [
                    'status'        => 'running',
                    'last_activity' => date('Y-m-d H:i:s'),
                ], 'id_job = ' . (int)$idJob);
                $this->confirmations[] = $this->l('Sync reanudado.');
                break;
        }

        $this->smView();
    }

    private function smView()
    {
        $connections = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'sync_connections` WHERE active = 1 ORDER BY name ASC'
        ) ?: [];

        $jobs = Db::getInstance()->executeS(
            'SELECT j.*, c.name AS connection_name
             FROM `' . _DB_PREFIX_ . 'sync_initial_job` j
             LEFT JOIN `' . _DB_PREFIX_ . 'sync_connections` c ON c.id_connection = j.id_connection
             ORDER BY j.date_add DESC LIMIT 20'
        ) ?: [];

        $this->context->smarty->assign([
            'connections'    => $connections,
            'jobs'           => $jobs,
            'master_stats'   => [
                'products'   => (int)Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product`'),
                'categories' => (int)Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'category` WHERE id_category > 2'),
                'images'     => (int)Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'image`'),
            ],
            'ajax_url'       => $this->context->link->getAdminLink('AdminSyncInitialSync'),
            'link_dashboard' => $this->context->link->getAdminLink('AdminSyncDashboard'),
        ]);

        $this->renderModuleTemplate('initial_sync.tpl');
    }

    private function smStart($idConn)
    {
        if (!$idConn) {
            $this->errors[] = $this->l('Selecciona una conexión primero.');
            $this->smView();
            return;
        }
        $conn = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'sync_connections` WHERE id_connection = ' . $idConn . ' AND active = 1'
        );
        if (!$conn) {
            $this->errors[] = $this->l('Conexión no encontrada o inactiva.');
            $this->smView();
            return;
        }

        $api   = new SyncMasterApi($conn['remote_url'], $conn['api_key'], $conn['api_secret']);
        $shake = $api->handshake(['batch_size' => (int)$conn['batch_size']]);
        if (!$shake['success']) {
            $this->errors[] = $this->l('No se puede conectar con la tienda hija: ') . $shake['error'];
            $this->smView();
            return;
        }

        $idJob = SyncMasterInitialJob::startOrResume($idConn);
        $this->confirmations[] = $this->l('Sync inicial iniciado. Job #') . $idJob;
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminSyncInitialSync'));
    }

    public function ajaxProcessNextBatch()
    {
        $idJob = (int)Tools::getValue('id_job', 0);
        if (!$idJob) { die(json_encode(['error' => 'Job ID inválido'])); }
        die(json_encode(SyncMasterInitialJob::processNextBatch($idJob)));
    }

    public function ajaxProcessJobStatus()
    {
        $idJob  = (int)Tools::getValue('id_job', 0);
        $status = SyncMasterInitialJob::getJobStatus($idJob);
        die(json_encode($status ?: ['error' => 'Job no encontrado']));
    }
}
