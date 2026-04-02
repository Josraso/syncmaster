<?php
/**
 * SyncMaster Pro
 * Módulo de sincronización de productos entre tiendas PrestaShop
 * Compatible: PrestaShop 1.6.x → 9.x
 *
 * @author    SyncMaster Pro
 * @version   1.0.1
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

spl_autoload_register(function ($class) {
    $file = dirname(__FILE__) . '/classes/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

class SyncMaster extends Module
{
    const ROLE_MASTER = 'master';
    const ROLE_SLAVE  = 'slave';
    const ROLE_BOTH   = 'both';

    public function __construct()
    {
        $this->name          = 'syncmaster';
        $this->tab           = 'administration';
        $this->version       = '1.0.1';
        $this->author        = 'SyncMaster Pro';
        $this->need_instance = 0;
        $this->bootstrap     = true;

        if (version_compare(_PS_VERSION_, '1.7.0', '>=')) {
            $this->ps_versions_compliancy = ['min' => '1.6.0', 'max' => _PS_VERSION_];
        }

        parent::__construct();

        $this->displayName = $this->l('SyncMaster Pro');
        $this->description = $this->l('Sincronización completa de productos entre tiendas PrestaShop. Compatible PS 1.6 a 9.');
        $this->confirmUninstall = $this->l('¿Seguro? Se eliminarán todas las conexiones y configuraciones.');
    }

    // =========================================================================
    // INSTALACIÓN / DESINSTALACIÓN
    // =========================================================================

    public function install()
    {
        if (!parent::install()) {
            return false;
        }
        if (!$this->createTables()) {
            $this->_errors[] = $this->l('Error al crear las tablas de la base de datos.');
            return false;
        }
        if (!$this->registerModuleHooks()) {
            $this->_errors[] = $this->l('Error al registrar los hooks.');
            return false;
        }
        // Registrar controllers admin como tabs ocultos (sin menú lateral)
        $this->registerAdminControllers();
        $this->setDefaultConfig();
        return true;
    }

    public function uninstall()
    {
        $this->dropTables();
        $this->unregisterAdminControllers();
        $this->deleteConfig();
        return parent::uninstall();
    }

    // =========================================================================
    // TABLAS BD
    // =========================================================================

    private function createTables()
    {
        $e = _MYSQL_ENGINE_;
        $p = _DB_PREFIX_;

        $queries = [
            "CREATE TABLE IF NOT EXISTS `{$p}sync_connections` (
                `id_connection` INT(11) NOT NULL AUTO_INCREMENT,
                `name`          VARCHAR(128) NOT NULL,
                `remote_url`    VARCHAR(512) NOT NULL,
                `api_key`       VARCHAR(64)  NOT NULL,
                `api_secret`    VARCHAR(128) NOT NULL,
                `id_mode`       ENUM('shared','free') NOT NULL DEFAULT 'free',
                `active`        TINYINT(1) NOT NULL DEFAULT 1,
                `sync_stock`    TINYINT(1) NOT NULL DEFAULT 1,
                `sync_prices`   TINYINT(1) NOT NULL DEFAULT 1,
                `sync_images`   TINYINT(1) NOT NULL DEFAULT 1,
                `batch_size`    SMALLINT(5) NOT NULL DEFAULT 50,
                `batch_delay`   SMALLINT(5) NOT NULL DEFAULT 1,
                `timeout`       SMALLINT(5) NOT NULL DEFAULT 30,
                `last_sync`     DATETIME DEFAULT NULL,
                `last_error`    TEXT DEFAULT NULL,
                `date_add`      DATETIME NOT NULL,
                `date_upd`      DATETIME NOT NULL,
                PRIMARY KEY (`id_connection`)
            ) ENGINE={$e} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `{$p}sync_field_config` (
                `id_config`        INT(11) NOT NULL AUTO_INCREMENT,
                `id_connection`    INT(11) NOT NULL,
                `field_group`      VARCHAR(64) NOT NULL,
                `field_name`       VARCHAR(64) NOT NULL,
                `sync_enabled`     TINYINT(1) NOT NULL DEFAULT 1,
                `overwrite_policy` ENUM('always','if_untouched','never') NOT NULL DEFAULT 'always',
                `price_rule`       ENUM('none','percent_inc','percent_dec','fixed_inc','fixed_dec') NOT NULL DEFAULT 'none',
                `price_value`      DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
                PRIMARY KEY (`id_config`),
                UNIQUE KEY `uq_connection_field` (`id_connection`, `field_name`),
                KEY `idx_connection` (`id_connection`)
            ) ENGINE={$e} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `{$p}sync_queue` (
                `id_queue`      INT(11) NOT NULL AUTO_INCREMENT,
                `id_connection` INT(11) NOT NULL,
                `entity_type`   VARCHAR(32) NOT NULL,
                `entity_id`     INT(11) NOT NULL,
                `action`        ENUM('create','update','delete') NOT NULL,
                `payload`       LONGTEXT NOT NULL,
                `status`        ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
                `attempts`      TINYINT(3) NOT NULL DEFAULT 0,
                `next_retry`    DATETIME DEFAULT NULL,
                `error_msg`     TEXT DEFAULT NULL,
                `date_add`      DATETIME NOT NULL,
                `date_done`     DATETIME DEFAULT NULL,
                PRIMARY KEY (`id_queue`),
                KEY `idx_status_retry` (`status`, `next_retry`),
                KEY `idx_connection` (`id_connection`)
            ) ENGINE={$e} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `{$p}sync_log` (
                `id_log`        INT(11) NOT NULL AUTO_INCREMENT,
                `id_connection` INT(11) NOT NULL,
                `entity_type`   VARCHAR(32) NOT NULL,
                `entity_id`     INT(11) DEFAULT NULL,
                `action`        VARCHAR(32) NOT NULL,
                `status`        ENUM('success','warning','error') NOT NULL,
                `message`       TEXT DEFAULT NULL,
                `duration_ms`   INT(11) DEFAULT NULL,
                `date_add`      DATETIME NOT NULL,
                PRIMARY KEY (`id_log`),
                KEY `idx_connection_date` (`id_connection`, `date_add`),
                KEY `idx_status` (`status`)
            ) ENGINE={$e} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `{$p}sync_id_map` (
                `id_map`        INT(11) NOT NULL AUTO_INCREMENT,
                `id_connection` INT(11) NOT NULL,
                `entity_type`   VARCHAR(32) NOT NULL,
                `master_id`     INT(11) NOT NULL,
                `local_id`      INT(11) NOT NULL,
                `field_hashes`  TEXT DEFAULT NULL,
                `last_sync`     DATETIME DEFAULT NULL,
                PRIMARY KEY (`id_map`),
                UNIQUE KEY `uq_map` (`id_connection`, `entity_type`, `master_id`),
                KEY `idx_local` (`id_connection`, `entity_type`, `local_id`)
            ) ENGINE={$e} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `{$p}sync_initial_job` (
                `id_job`          INT(11) NOT NULL AUTO_INCREMENT,
                `id_connection`   INT(11) NOT NULL,
                `status`          ENUM('pending','running','paused','done','failed') NOT NULL DEFAULT 'pending',
                `phase`           ENUM('categories','manufacturers','attributes','features','products','images','verification') NOT NULL DEFAULT 'categories',
                `total_items`     INT(11) NOT NULL DEFAULT 0,
                `total_batches`   INT(11) NOT NULL DEFAULT 0,
                `current_batch`   INT(11) NOT NULL DEFAULT 0,
                `processed_items` INT(11) NOT NULL DEFAULT 0,
                `failed_items`    INT(11) NOT NULL DEFAULT 0,
                `batch_size`      SMALLINT(5) NOT NULL DEFAULT 50,
                `progress_pct`    TINYINT(3) NOT NULL DEFAULT 0,
                `started_at`      DATETIME DEFAULT NULL,
                `last_activity`   DATETIME DEFAULT NULL,
                `completed_at`    DATETIME DEFAULT NULL,
                `error_log`       TEXT DEFAULT NULL,
                `date_add`        DATETIME NOT NULL,
                PRIMARY KEY (`id_job`),
                KEY `idx_connection_status` (`id_connection`, `status`)
            ) ENGINE={$e} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `{$p}sync_initial_batch` (
                `id_batch`      INT(11) NOT NULL AUTO_INCREMENT,
                `id_job`        INT(11) NOT NULL,
                `batch_number`  INT(11) NOT NULL,
                `phase`         VARCHAR(32) NOT NULL,
                `offset`        INT(11) NOT NULL DEFAULT 0,
                `limit_size`    INT(11) NOT NULL DEFAULT 50,
                `items_sent`    INT(11) NOT NULL DEFAULT 0,
                `items_ok`      INT(11) NOT NULL DEFAULT 0,
                `items_failed`  INT(11) NOT NULL DEFAULT 0,
                `status`        ENUM('pending','done','failed') NOT NULL DEFAULT 'pending',
                `duration_ms`   INT(11) DEFAULT NULL,
                `error_msg`     TEXT DEFAULT NULL,
                `date_add`      DATETIME NOT NULL,
                PRIMARY KEY (`id_batch`),
                KEY `idx_job_batch` (`id_job`, `batch_number`)
            ) ENGINE={$e} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];

        foreach ($queries as $query) {
            if (!Db::getInstance()->execute($query)) {
                return false;
            }
        }
        return true;
    }

    private function dropTables()
    {
        foreach (['sync_connections','sync_field_config','sync_queue','sync_log',
                  'sync_id_map','sync_initial_job','sync_initial_batch'] as $t) {
            Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . $t . '`');
        }
    }

    // =========================================================================
    // REGISTRO DE HOOKS
    //
    // REGLA PS 8+: cada hook en esta lista DEBE tener su método hookXxx definido
    // en esta clase. Nunca registrar un hook sin el método correspondiente.
    // =========================================================================

    private function registerModuleHooks()
    {
        // Hooks universales 1.6 → 9 — todos tienen su método abajo
        $hooks = [
            'actionProductAdd',
            'actionProductSave',
            'actionProductDelete',
            'actionUpdateQuantity',
            'actionCategoryAdd',
            'actionCategoryUpdate',
            'actionCategoryDelete',
            'actionAttributeGroupSave',
            'actionAttributeSave',
            'actionFeatureSave',
            'actionFeatureValueSave',
            'displayBackOfficeHeader',
            'actionAdminControllerSetMedia',
        ];

        // Hooks exclusivos PS 1.7+ — también tienen su método abajo
        if (version_compare(_PS_VERSION_, '1.7.0', '>=')) {
            $hooks[] = 'actionProductUpdate';
            $hooks[] = 'actionProductDuplicate';
            $hooks[] = 'actionObjectProductAddAfter';
            $hooks[] = 'actionObjectProductUpdateAfter';
            $hooks[] = 'actionObjectProductDeleteAfter';
        }

        foreach ($hooks as $hook) {
            $this->registerHook($hook);
        }

        return true;
    }

    // =========================================================================
    // MÉTODOS DE HOOK — PRODUCTOS
    // Uno por cada hook registrado arriba. Sin excepción.
    // =========================================================================

    /** Hook: actionProductAdd — PS 1.6 → 9 */
    public function hookActionProductAdd($params)
    {
        $this->dispatchProductEvent('create', $params);
    }

    /**
     * Hook: actionProductSave — PS 1.6 → 9
     * En PS 1.6 este hook cubre create + update.
     * En PS 1.7+ coexiste con Add/Update; usamos flag estático para no duplicar.
     */
    public function hookActionProductSave($params)
    {
        $idProduct = $this->extractProductId($params);
        if (!$idProduct) {
            return;
        }
        static $savedIds = [];
        if (isset($savedIds[$idProduct])) {
            return;
        }
        $savedIds[$idProduct] = true;
        $this->dispatchProductEvent('update', $params);
    }

    /** Hook: actionProductUpdate — PS 1.7+ */
    public function hookActionProductUpdate($params)
    {
        $this->dispatchProductEvent('update', $params);
    }

    /** Hook: actionProductDelete — PS 1.6 → 9 */
    public function hookActionProductDelete($params)
    {
        $this->dispatchProductEvent('delete', $params);
    }

    /** Hook: actionProductDuplicate — PS 1.7+ */
    public function hookActionProductDuplicate($params)
    {
        if (!empty($params['id_product_new'])) {
            $params['id_product'] = (int)$params['id_product_new'];
        }
        $this->dispatchProductEvent('create', $params);
    }

    /** Hook: actionObjectProductAddAfter — PS 1.7+ */
    public function hookActionObjectProductAddAfter($params)
    {
        $this->dispatchProductEvent('create', ['object' => $params['object'] ?? null]);
    }

    /** Hook: actionObjectProductUpdateAfter — PS 1.7+ */
    public function hookActionObjectProductUpdateAfter($params)
    {
        $this->dispatchProductEvent('update', ['object' => $params['object'] ?? null]);
    }

    /** Hook: actionObjectProductDeleteAfter — PS 1.7+ */
    public function hookActionObjectProductDeleteAfter($params)
    {
        $this->dispatchProductEvent('delete', ['object' => $params['object'] ?? null]);
    }

    // =========================================================================
    // MÉTODOS DE HOOK — STOCK
    // =========================================================================

    /** Hook: actionUpdateQuantity — PS 1.6 → 9 */
    public function hookActionUpdateQuantity($params)
    {
        if (!Configuration::get('SYNCMASTER_SYNC_STOCK')) {
            return;
        }
        $role = Configuration::get('SYNCMASTER_ROLE');
        if (!in_array($role, [self::ROLE_MASTER, self::ROLE_BOTH])) {
            return;
        }

        $idProduct          = isset($params['id_product'])          ? (int)$params['id_product']          : 0;
        $idProductAttribute = isset($params['id_product_attribute']) ? (int)$params['id_product_attribute'] : 0;
        $quantity           = isset($params['quantity'])             ? (int)$params['quantity']             : 0;

        if (!$idProduct) {
            return;
        }

        $this->loadClasses();
        SyncMasterQueue::enqueueStock($idProduct, $idProductAttribute, $quantity);
    }

    // =========================================================================
    // MÉTODOS DE HOOK — CATEGORÍAS
    // =========================================================================

    /** Hook: actionCategoryAdd — PS 1.6 → 9 */
    public function hookActionCategoryAdd($params)
    {
        $this->dispatchCategoryEvent('create', $params);
    }

    /** Hook: actionCategoryUpdate — PS 1.6 → 9 */
    public function hookActionCategoryUpdate($params)
    {
        $this->dispatchCategoryEvent('update', $params);
    }

    /** Hook: actionCategoryDelete — PS 1.6 → 9 */
    public function hookActionCategoryDelete($params)
    {
        $this->dispatchCategoryEvent('delete', $params);
    }

    // =========================================================================
    // MÉTODOS DE HOOK — ATRIBUTOS Y CARACTERÍSTICAS
    // =========================================================================

    /** Hook: actionAttributeGroupSave — PS 1.6 → 9 */
    public function hookActionAttributeGroupSave($params)
    {
        $this->dispatchEntityEvent('attribute_group', 'update', $params);
    }

    /** Hook: actionAttributeSave — PS 1.6 → 9 */
    public function hookActionAttributeSave($params)
    {
        $this->dispatchEntityEvent('attribute', 'update', $params);
    }

    /** Hook: actionFeatureSave — PS 1.6 → 9 */
    public function hookActionFeatureSave($params)
    {
        $this->dispatchEntityEvent('feature', 'update', $params);
    }

    /** Hook: actionFeatureValueSave — PS 1.6 → 9 */
    public function hookActionFeatureValueSave($params)
    {
        $this->dispatchEntityEvent('feature_value', 'update', $params);
    }

    // =========================================================================
    // MÉTODOS DE HOOK — ADMIN
    // =========================================================================

    /** Hook: displayBackOfficeHeader — PS 1.6 → 9 */
    public function hookDisplayBackOfficeHeader()
    {
        $ctrl = Tools::getValue('controller', '');
        // Cargar assets en todos los controllers AdminSync* y también en AdminModules
        // (cuando el admin pulsa "Configurar" el controller es AdminModules)
        if (strpos($ctrl, 'AdminSync') !== false || $ctrl === 'AdminModules') {
            $this->context->controller->addJS($this->_path . 'views/js/syncmaster-admin.js');
            $this->context->controller->addCSS($this->_path . 'views/css/syncmaster-admin.css');
        }
    }

    /** Hook: actionAdminControllerSetMedia — PS 1.7+ */
    public function hookActionAdminControllerSetMedia()
    {
        $this->hookDisplayBackOfficeHeader();
    }

    // =========================================================================
    // DISPATCHERS INTERNOS
    // =========================================================================

    private function dispatchProductEvent($action, $params)
    {
        $role = Configuration::get('SYNCMASTER_ROLE');
        if (!in_array($role, [self::ROLE_MASTER, self::ROLE_BOTH])) {
            return;
        }
        $idProduct = $this->extractProductId($params);
        if (!$idProduct) {
            return;
        }
        $this->loadClasses();
        SyncMasterQueue::enqueue('product', $idProduct, $action);
    }

    private function dispatchCategoryEvent($action, $params)
    {
        $role = Configuration::get('SYNCMASTER_ROLE');
        if (!in_array($role, [self::ROLE_MASTER, self::ROLE_BOTH])) {
            return;
        }
        $idCategory = !empty($params['id_category'])
            ? (int)$params['id_category']
            : (isset($params['object']) ? (int)$params['object']->id : 0);
        if (!$idCategory) {
            return;
        }
        $this->loadClasses();
        SyncMasterQueue::enqueue('category', $idCategory, $action);
    }

    private function dispatchEntityEvent($entityType, $action, $params)
    {
        $role = Configuration::get('SYNCMASTER_ROLE');
        if (!in_array($role, [self::ROLE_MASTER, self::ROLE_BOTH])) {
            return;
        }
        $id = isset($params['object']) ? (int)$params['object']->id : 0;
        if (!$id) {
            return;
        }
        $this->loadClasses();
        SyncMasterQueue::enqueue($entityType, $id, $action);
    }

    private function extractProductId($params)
    {
        if (!empty($params['id_product']))     return (int)$params['id_product'];
        if (!empty($params['id_product_new'])) return (int)$params['id_product_new'];
        if (isset($params['object']) && is_object($params['object']) && !empty($params['object']->id)) {
            return (int)$params['object']->id;
        }
        if (isset($params['product']) && is_object($params['product']) && !empty($params['product']->id)) {
            return (int)$params['product']->id;
        }
        return 0;
    }

    /**
     * Carga lazy de las clases del módulo en los hooks.
     * El autoloader puede no haber corrido todavía cuando PS llama a un hook.
     */
    private function loadClasses()
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $loaded = true;
        $dir    = dirname(__FILE__) . '/classes/';
        foreach ([
            'SyncMasterVersionCompat',
            'SyncMasterHelpers',   // SyncMasterFieldConfig + SyncMasterPriceRule + SyncMasterLogger
            'SyncMasterSerializer',
            'SyncMasterQueue',
        ] as $class) {
            if (!class_exists($class) && file_exists($dir . $class . '.php')) {
                require_once $dir . $class . '.php';
            }
        }
    }

    // =========================================================================
    // CONTROLLERS ADMIN — TABS OCULTOS (sin menú lateral)
    // =========================================================================

    /**
     * Registra los controllers admin como tabs con id_parent = -1.
     * Esto los hace reconocibles por PS para el routing de URLs
     * sin que aparezcan en el menú lateral.
     * Funciona igual en PS 1.6, 1.7, 8 y 9.
     */
    private function registerAdminControllers()
    {
        $controllers = [
            'AdminSyncDashboard',
            'AdminSyncConnections',
            'AdminSyncFields',
            'AdminSyncInitialSync',
            'AdminSyncLogs',
        ];

        foreach ($controllers as $class) {
            if (Tab::getIdFromClassName($class)) {
                continue; // Ya registrado
            }
            $tab             = new Tab();
            $tab->class_name = $class;
            $tab->module     = $this->name;
            $tab->active     = 1;
            $tab->id_parent  = -1; // -1 = oculto en menú lateral
            foreach (Language::getLanguages(false) as $lang) {
                $tab->name[$lang['id_lang']] = $class; // El nombre no importa, es invisible
            }
            $tab->add();
        }
    }

    private function unregisterAdminControllers()
    {
        $classes = [
            'AdminSyncDashboard', 'AdminSyncConnections', 'AdminSyncFields',
            'AdminSyncInitialSync', 'AdminSyncLogs',
        ];
        foreach ($classes as $class) {
            $id = (int)Tab::getIdFromClassName($class);
            if ($id) {
                $tabObj = new Tab($id);
                $tabObj->delete();
            }
        }
    }

    // =========================================================================
    // CONFIGURACIÓN
    // =========================================================================

    private function setDefaultConfig()
    {
        Configuration::updateValue('SYNCMASTER_ROLE',          self::ROLE_MASTER);
        Configuration::updateValue('SYNCMASTER_SYNC_STOCK',    1);
        Configuration::updateValue('SYNCMASTER_SYNC_IMAGES',   1);
        Configuration::updateValue('SYNCMASTER_CRON_TOKEN',    md5(uniqid('syncmaster_', true)));
        Configuration::updateValue('SYNCMASTER_QUEUE_WORKER',  1);
        Configuration::updateValue('SYNCMASTER_LOG_RETENTION', 30);
    }

    private function deleteConfig()
    {
        foreach ([
            'SYNCMASTER_ROLE', 'SYNCMASTER_SYNC_STOCK', 'SYNCMASTER_SYNC_IMAGES',
            'SYNCMASTER_CRON_TOKEN', 'SYNCMASTER_QUEUE_WORKER', 'SYNCMASTER_LOG_RETENTION',
        ] as $key) {
            Configuration::deleteByName($key);
        }
    }

    // =========================================================================
    // PANEL DE CONFIGURACIÓN (desde lista de módulos → "Configurar")
    // =========================================================================

    /**
     * getContent() es llamado cuando el admin pulsa "Configurar" en la lista de módulos.
     * No redirigimos a otro controller para evitar problemas de routing.
     * Renderizamos el dashboard directamente aquí con smarty->fetch().
     */
    public function getContent()
    {
        // Cargar clases necesarias
        $dir = dirname(__FILE__) . '/classes/';
        foreach (['SyncMasterHelpers', 'SyncMasterQueue', 'SyncMasterApi', 'SyncMasterVersionCompat'] as $cls) {
            if (!class_exists($cls) && file_exists($dir . $cls . '.php')) {
                require_once $dir . $cls . '.php';
            }
        }

        // Forzar carga de CSS/JS en el contexto de "Configurar" (controller = AdminModules)
        // El hook displayBackOfficeHeader no se dispara aquí porque el controller no es AdminSync*
        $this->context->controller->addCSS($this->_path . 'views/css/syncmaster-admin.css');
        $this->context->controller->addJS($this->_path . 'views/js/syncmaster-admin.js');

        $tplDir  = dirname(__FILE__) . '/views/templates/admin/';
        $tplPath = $tplDir . 'dashboard.tpl';

        // Ejecutar cola pendiente
        if (Configuration::get('SYNCMASTER_QUEUE_WORKER')) {
            SyncMasterQueue::processQueue(10);
        }

        $connections   = Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'sync_connections` ORDER BY name ASC') ?: [];
        $queueStats    = SyncMasterQueue::getStats();
        $recentErrors  = SyncMasterLogger::getRecent(10, null, 'error');
        $cronToken     = Configuration::get('SYNCMASTER_CRON_TOKEN');
        $cronUrl       = Tools::getShopDomainSsl(true) . __PS_BASE_URI__
            . 'modules/syncmaster/cron/retry_queue.php?token=' . $cronToken;

        // Generar URLs para cada sección usando los controllers ocultos
        $adminLink = $this->context->link;
        $links = [
            'connections' => $adminLink->getAdminLink('AdminSyncConnections'),
            'fields'      => $adminLink->getAdminLink('AdminSyncFields'),
            'sync'        => $adminLink->getAdminLink('AdminSyncInitialSync'),
            'logs'        => $adminLink->getAdminLink('AdminSyncLogs'),
            'dashboard'   => $adminLink->getAdminLink('AdminSyncDashboard'),
        ];

        $this->context->smarty->addTemplateDir($tplDir);
        $this->context->smarty->assign([
            'syncmaster_connections'    => $connections,
            'syncmaster_queue_stats'    => $queueStats,
            'syncmaster_recent_errors'  => $recentErrors,
            'syncmaster_recent_success' => [],
            'syncmaster_cron_url'       => $cronUrl,
            'syncmaster_role'           => Configuration::get('SYNCMASTER_ROLE'),
            'syncmaster_ping_results'   => [],
            'syncmaster_ps_version'     => _PS_VERSION_,
            'syncmaster_module_version' => $this->version,
            'syncmaster_ps_root_dir'    => _PS_ROOT_DIR_,
            'syncmaster_ajax_url'       => $links['dashboard'],
            'link_connections'          => $links['connections'],
            'link_fields'               => $links['fields'],
            'link_sync'                 => $links['sync'],
            'link_logs'                 => $links['logs'],
        ]);

        return $this->context->smarty->fetch($tplPath);
    }
}
