<?php
/**
 * SyncMaster Pro
 * Módulo de sincronización de productos entre tiendas PrestaShop
 * Compatible: PrestaShop 1.6.x → 9.x
 *
 * @author    SyncMaster Pro
 * @version   1.1.0
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
        $this->version       = '1.1.0';
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
                `delete_on_slave` TINYINT(1) NOT NULL DEFAULT 1,
                `category_filter` TEXT DEFAULT NULL,
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
                `id_queue`            INT(11) NOT NULL AUTO_INCREMENT,
                `id_connection`       INT(11) NOT NULL,
                `entity_type`         VARCHAR(32) NOT NULL,
                `entity_id`           INT(11) NOT NULL,
                `id_product_attribute` INT(11) NOT NULL DEFAULT 0,
                `action`              ENUM('create','update','delete') NOT NULL,
                `payload`             LONGTEXT NOT NULL,
                `status`              ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
                `attempts`            TINYINT(3) NOT NULL DEFAULT 0,
                `next_retry`          DATETIME DEFAULT NULL,
                `error_msg`           TEXT DEFAULT NULL,
                `date_add`            DATETIME NOT NULL,
                `date_done`           DATETIME DEFAULT NULL,
                PRIMARY KEY (`id_queue`),
                KEY `idx_status_retry` (`status`, `next_retry`),
                KEY `idx_connection` (`id_connection`),
                KEY `idx_stock_dedup` (`id_connection`, `entity_type`, `entity_id`, `id_product_attribute`, `status`)
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
                `skip_images`     TINYINT(1) NOT NULL DEFAULT 0,
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

    /**
     * Migración incremental: añade columnas nuevas a tablas existentes.
     * Se llama en cada carga del panel admin; las columnas ya existentes se ignoran.
     */
    private function upgradeSchema()
    {
        $p  = _DB_PREFIX_;
        $db = Db::getInstance();

        $migrations = [
            'sync_connections' => [
                'delete_on_slave' => "ALTER TABLE `{$p}sync_connections`
                    ADD COLUMN `delete_on_slave` TINYINT(1) NOT NULL DEFAULT 1 AFTER `sync_images`",
                'category_filter' => "ALTER TABLE `{$p}sync_connections`
                    ADD COLUMN `category_filter` TEXT DEFAULT NULL AFTER `delete_on_slave`",
                'lang_filter'     => "ALTER TABLE `{$p}sync_connections`
                    ADD COLUMN `lang_filter` VARCHAR(255) DEFAULT NULL AFTER `category_filter`",
                'lang_map'        => "ALTER TABLE `{$p}sync_connections`
                    ADD COLUMN `lang_map` VARCHAR(500) DEFAULT NULL AFTER `lang_filter`",
            ],
            'sync_initial_job' => [
                'skip_images' => "ALTER TABLE `{$p}sync_initial_job`
                    ADD COLUMN `skip_images` TINYINT(1) NOT NULL DEFAULT 0 AFTER `batch_size`",
            ],
        ];

        foreach ($migrations as $table => $columns) {
            $existing = [];
            $rows = $db->executeS(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . pSQL($p . $table) . "'"
            );
            if ($rows) {
                foreach ($rows as $row) {
                    $existing[] = strtolower($row['COLUMN_NAME']);
                }
            }
            foreach ($columns as $col => $sql) {
                if (!in_array(strtolower($col), $existing)) {
                    $db->execute($sql);
                }
            }
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
        Configuration::updateValue('SYNCMASTER_ROLE',            self::ROLE_MASTER);
        Configuration::updateValue('SYNCMASTER_SYNC_STOCK',      1);
        Configuration::updateValue('SYNCMASTER_SYNC_IMAGES',     1);
        Configuration::updateValue('SYNCMASTER_CRON_TOKEN',      md5(uniqid('syncmaster_', true)));
        Configuration::updateValue('SYNCMASTER_QUEUE_WORKER',    1);
        Configuration::updateValue('SYNCMASTER_LOG_RETENTION',   30);
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
    // PANEL DE CONFIGURACIÓN — todo el módulo vive aquí para máxima
    // compatibilidad PS 1.6 → 9 (los sub-controllers dan página en blanco en PS 8.2)
    // =========================================================================

    /**
     * Punto de entrada único. Enruta según sm_section y sm_action GET/POST params.
     * AJAX detectado con sm_ajax param → responde JSON y termina.
     */
    public function getContent()
    {
        $this->upgradeSchema();
        $this->smLoadClasses();

        $this->context->controller->addCSS($this->_path . 'views/css/syncmaster-admin.css');
        $this->context->controller->addJS($this->_path . 'views/js/syncmaster-admin.js');

        $baseUrl = $this->context->link->getAdminLink('AdminModules') . '&configure=' . $this->name;

        $smAjax = Tools::getValue('sm_ajax', '');
        if ($smAjax) {
            $this->smAjaxDispatch($smAjax);
            exit;
        }

        $section = Tools::getValue('sm_section', 'dashboard');
        $action  = Tools::getValue('sm_action', '');
        $idConn  = (int)Tools::getValue('id_connection', 0);
        $idJob   = (int)Tools::getValue('id_job', 0);

        switch ($section) {
            case 'connections': return $this->smConnections($baseUrl, $action, $idConn);
            case 'fields':      return $this->smFields($baseUrl, $action, $idConn);
            case 'sync':        return $this->smSync($baseUrl, $action, $idConn, $idJob);
            case 'logs':        return $this->smLogs($baseUrl, $action, $idConn);
            case 'reset':       return $this->smReset($baseUrl, $action);
            default:            return $this->smDashboard($baseUrl);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers internos de getContent()
    // -------------------------------------------------------------------------

    private function smLoadClasses()
    {
        $dir = dirname(__FILE__) . '/classes/';
        foreach ([
            'SyncMasterVersionCompat', 'SyncMasterHelpers', 'SyncMasterQueue',
            'SyncMasterApi', 'SyncMasterFieldConfig', 'SyncMasterInitialJob',
            'SyncMasterSerializer', 'SyncMasterLogger',
        ] as $cls) {
            if (!class_exists($cls) && file_exists($dir . $cls . '.php')) {
                require_once $dir . $cls . '.php';
            }
        }
    }

    /** Renderiza una plantilla del módulo y devuelve el HTML. */
    private function smFetch($tplName, $vars)
    {
        $tplDir = dirname(__FILE__) . '/views/templates/admin/';
        $this->context->smarty->addTemplateDir($tplDir);
        // Force recompile so template changes are picked up without clearing PS cache
        $this->context->smarty->force_compile = true;
        $this->context->smarty->assign($vars);
        $html = $this->context->smarty->fetch($tplDir . $tplName);
        $this->context->smarty->force_compile = false;
        return $html;
    }

    /** Responde peticiones AJAX con JSON. */
    private function smAjaxDispatch($action)
    {
        header('Content-Type: application/json');
        switch ($action) {
            case 'runQueue':
                $stats = SyncMasterQueue::processQueue(50);
                echo json_encode(['success' => true, 'stats' => $stats]);
                break;
            case 'ping':
                $idConn = (int)Tools::getValue('id_connection', 0);
                if (!$idConn) { echo json_encode(['success' => false, 'error' => 'ID no válido']); break; }
                $conn = Db::getInstance()->getRow(
                    'SELECT * FROM `' . _DB_PREFIX_ . 'sync_connections` WHERE id_connection = ' . $idConn
                );
                if (!$conn) { echo json_encode(['success' => false, 'error' => 'Conexión no encontrada']); break; }
                $api = new SyncMasterApi($conn['remote_url'], $conn['api_key'], $conn['api_secret']);
                echo json_encode($api->ping());
                break;
            case 'nextBatch':
                $idJob = (int)Tools::getValue('id_job', 0);
                if (!$idJob) { echo json_encode(['error' => 'Job ID inválido']); break; }
                echo json_encode(SyncMasterInitialJob::processNextBatch($idJob));
                break;
            default:
                echo json_encode(['error' => 'Acción desconocida']);
        }
    }

    // -------------------------------------------------------------------------
    // Secciones
    // -------------------------------------------------------------------------

    /**
     * Reset completo: borra todas las tablas y la configuración,
     * luego las recrea vacías como si fuera una instalación limpia.
     */
    private function smReset($baseUrl, $action)
    {
        // Detectar submit del formulario de confirmación (POST o GET)
        $submitted = Tools::getValue('sm_reset_confirm', '');
        if ($submitted === '1') {
            // Ejecutar reset sin verificar token adicional — la doble confirmación
            // (botón en dashboard + confirm JS + este submit) es suficiente protección
            $errors = [];
            try { $this->dropTables(); }      catch (Exception $e) { $errors[] = 'dropTables: ' . $e->getMessage(); }
            try { $this->deleteConfig(); }    catch (Exception $e) { $errors[] = 'deleteConfig: ' . $e->getMessage(); }
            try { $this->createTables(); }    catch (Exception $e) { $errors[] = 'createTables: ' . $e->getMessage(); }
            try { $this->setDefaultConfig(); } catch (Exception $e) { $errors[] = 'setDefaultConfig: ' . $e->getMessage(); }

            if (method_exists('Configuration', 'clearConfigurationCacheForAllShops')) {
                Configuration::clearConfigurationCacheForAllShops();
            }

            if ($errors) {
                return '<div class="syncmaster-wrap">
                    <div class="alert alert-danger">
                        <strong>Reset completado con errores:</strong><br>'
                        . implode('<br>', array_map('htmlspecialchars', $errors)) . '
                    </div>
                    <a href="' . $baseUrl . '" class="btn btn-primary">Ir al Panel</a>
                </div>';
            }

            return '<div class="syncmaster-wrap">
                <div class="alert alert-success" style="font-size:16px">
                    <strong><i class="icon-ok"></i> Reset completado correctamente.</strong><br>
                    Todas las tablas, conexiones y configuración del módulo han sido borradas y recreadas.
                    El módulo está como recién instalado.
                </div>
                <a href="' . $baseUrl . '" class="btn btn-primary btn-lg">
                    <i class="icon-home"></i> Ir al Panel
                </a>
            </div>';
        }

        // Mostrar página de confirmación con formulario POST estándar
        return '<div class="syncmaster-wrap">
            <a href="' . $baseUrl . '" class="btn btn-default btn-sm" style="margin-bottom:8px">
                <i class="icon-arrow-left"></i> Panel
            </a>
            <h2 style="margin-top:4px;color:#d9534f">⚠ Reset completo del módulo</h2>
            <div class="alert alert-danger" style="font-size:14px">
                <strong>¡Atención!</strong> Esta acción borrará <strong>TODOS</strong> los datos del módulo:<br>
                conexiones, configuración de campos, cola de sincronización, registros, jobs de sync inicial y mapas de IDs.<br><br>
                La configuración quedará como si acabaras de instalar el módulo por primera vez.<br>
                <strong>Esta acción NO se puede deshacer.</strong>
            </div>
            <form method="post" action="' . $baseUrl . '&sm_section=reset">
                <input type="hidden" name="sm_reset_confirm" value="1">
                <button type="submit" class="btn btn-danger btn-lg"
                        onclick="return confirm(\'¿Estás completamente SEGURO?\nSe borrarán TODAS las conexiones y datos del módulo.\')">
                    <i class="icon-trash"></i> Sí, borrar todo y empezar de cero
                </button>
                <a href="' . $baseUrl . '" class="btn btn-default btn-lg" style="margin-left:10px">Cancelar</a>
            </form>
        </div>';
    }

    private function smDashboard($baseUrl)
    {
        $confirm = '';
        if (Tools::isSubmit('submitSyncMasterSettings')) {
            $role = Tools::getValue('syncmaster_role', 'master');
            if (!in_array($role, [self::ROLE_MASTER, self::ROLE_SLAVE, self::ROLE_BOTH])) {
                $role = self::ROLE_MASTER;
            }
            Configuration::updateValue('SYNCMASTER_ROLE', $role);
            $confirm = $this->l('Configuración guardada correctamente.');
        }

        if (Configuration::get('SYNCMASTER_QUEUE_WORKER')) {
            SyncMasterQueue::processQueue(10);
        }

        $connections  = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'sync_connections` ORDER BY name ASC'
        ) ?: [];
        $queueStats   = SyncMasterQueue::getStats();
        $recentErrors = SyncMasterLogger::getRecent(10, null, 'error');
        $cronToken    = Configuration::get('SYNCMASTER_CRON_TOKEN');
        $cronUrl      = Tools::getShopDomainSsl(true) . __PS_BASE_URI__
            . 'modules/syncmaster/cron/retry_queue.php?token=' . $cronToken;

        return $this->smFetch('dashboard.tpl', [
            'syncmaster_connections'      => $connections,
            'syncmaster_queue_stats'      => $queueStats,
            'syncmaster_recent_errors'    => $recentErrors,
            'syncmaster_recent_success'   => [],
            'syncmaster_cron_url'         => $cronUrl,
            'syncmaster_role'             => Configuration::get('SYNCMASTER_ROLE'),
            'syncmaster_ping_results'     => [],
            'syncmaster_ps_version'       => _PS_VERSION_,
            'syncmaster_module_version'   => $this->version,
            'syncmaster_ps_root_dir'      => _PS_ROOT_DIR_,
            'syncmaster_ajax_url'         => $baseUrl,
            'syncmaster_settings_confirm' => $confirm,
            'sm_base_url'                 => $baseUrl,
            'link_connections'            => $baseUrl . '&sm_section=connections',
            'link_fields'                 => $baseUrl . '&sm_section=fields',
            'link_sync'                   => $baseUrl . '&sm_section=sync',
            'link_logs'                   => $baseUrl . '&sm_section=logs',
            'link_reset'                  => $baseUrl . '&sm_section=reset',
        ]);
    }

    private function smConnections($baseUrl, $action, $idConn)
    {
        $listUrl = $baseUrl . '&sm_section=connections';

        switch ($action) {
            case 'add':
            case 'edit':
                return $this->smConnectionForm($baseUrl, $idConn, []);
            case 'save':
                return $this->smConnectionSave($baseUrl, $idConn);
            case 'delete':
                if ($idConn) {
                    foreach (['sync_connections','sync_field_config','sync_queue',
                              'sync_id_map','sync_initial_job'] as $t) {
                        Db::getInstance()->execute(
                            'DELETE FROM `' . _DB_PREFIX_ . $t . '` WHERE id_connection = ' . $idConn
                        );
                    }
                }
                Tools::redirectAdmin($listUrl);
                return '';
            case 'toggle':
                if ($idConn) {
                    $cur = (int)Db::getInstance()->getValue(
                        'SELECT active FROM `' . _DB_PREFIX_ . 'sync_connections`'
                        . ' WHERE id_connection = ' . $idConn
                    );
                    Db::getInstance()->update('sync_connections', [
                        'active'   => $cur ? 0 : 1,
                        'date_upd' => date('Y-m-d H:i:s'),
                    ], 'id_connection = ' . $idConn);
                }
                Tools::redirectAdmin($listUrl);
                return '';

            case 'start_no_images':
                // MASTER: lanza un job local de sync inicial sin imágenes hacia la slave
                if ($idConn) {
                    $conn = Db::getInstance()->getRow(
                        'SELECT * FROM `' . _DB_PREFIX_ . 'sync_connections`'
                        . ' WHERE id_connection = ' . $idConn . ' AND active = 1'
                    );
                    if ($conn) {
                        $api   = new SyncMasterApi($conn['remote_url'], $conn['api_key'], $conn['api_secret']);
                        $shake = $api->handshake(['batch_size' => (int)$conn['batch_size']]);
                        if ($shake['success']) {
                            SyncMasterInitialJob::startOrResume($idConn, true);
                        }
                    }
                }
                Tools::redirectAdmin($baseUrl . '&sm_section=sync');
                return '';

            case 'request_resync':
                // SLAVE: solicita al master que lance un job de resync sin imágenes
                if ($idConn) {
                    $conn = Db::getInstance()->getRow(
                        'SELECT * FROM `' . _DB_PREFIX_ . 'sync_connections`'
                        . ' WHERE id_connection = ' . $idConn . ' AND active = 1'
                    );
                    if ($conn) {
                        $api = new SyncMasterApi($conn['remote_url'], $conn['api_key'], $conn['api_secret']);
                        $api->requestResync(true); // skip_images=true
                    }
                }
                Tools::redirectAdmin($listUrl . '&sm_resync_ok=1');
                return '';
        }

        $connections = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'sync_connections` ORDER BY name ASC'
        ) ?: [];

        $storeRole = Configuration::get('SYNCMASTER_ROLE') ?: self::ROLE_MASTER;

        return $this->smFetch('connections_list.tpl', [
            'connections'    => $connections,
            'link_add'       => $listUrl . '&sm_action=add',
            'current_url'    => $listUrl,
            'sm_base_url'    => $baseUrl,
            'link_dashboard' => $baseUrl,
            'store_role'     => $storeRole,
            'resync_ok'      => (bool)Tools::getValue('sm_resync_ok', 0),
        ]);
    }

    private function smConnectionForm($baseUrl, $idConn, $errors)
    {
        $listUrl    = $baseUrl . '&sm_section=connections';
        // Defaults for all keys the template accesses (avoids "Undefined array key" in PS9/PHP8)
        $connection = [
            'id_connection'  => null,
            'name'           => '',
            'remote_url'     => '',
            'api_key'        => '',
            'api_secret'     => '',
            'id_mode'        => 'free',
            'sync_stock'     => 1,
            'sync_prices'    => 1,
            'sync_images'    => 1,
            'delete_on_slave'=> 1,
            'category_filter'=> '',
            'lang_filter'    => '',
            'lang_map'       => '',
            'batch_size'     => 50,
            'batch_delay'    => 1,
            'timeout'        => 30,
            'active'         => 1,
        ];
        if ($idConn) {
            $row = Db::getInstance()->getRow(
                'SELECT * FROM `' . _DB_PREFIX_ . 'sync_connections` WHERE id_connection = ' . $idConn
            );
            if ($row) {
                $connection = array_merge($connection, $row);
            }
        }

        $storeRole = Configuration::get('SYNCMASTER_ROLE') ?: self::ROLE_MASTER;
        $isMaster  = in_array($storeRole, [self::ROLE_MASTER, self::ROLE_BOTH]);

        // Categorías disponibles para el filtro (solo modo master)
        $idLang = (int)Configuration::get('PS_LANG_DEFAULT');
        $allCategories = $isMaster ? (Db::getInstance()->executeS(
            'SELECT c.id_category, c.id_parent, cl.name, c.level_depth
             FROM `' . _DB_PREFIX_ . 'category` c
             INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                 ON cl.id_category = c.id_category AND cl.id_lang = ' . $idLang . '
             WHERE c.id_category > 2 AND c.active = 1
             ORDER BY c.nleft ASC'
        ) ?: []) : [];

        $selectedCategories = [];
        if (!empty($connection['category_filter'])) {
            $selectedCategories = array_map('intval', explode(',', $connection['category_filter']));
        }

        $selectedLangIsos = [];
        if (!empty($connection['lang_filter'])) {
            $selectedLangIsos = array_filter(array_map('trim', explode(',', $connection['lang_filter'])));
        }

        // lang_map: "master_iso:local_iso,..." — para slave, mapear ISO entrante a idioma local
        $langMapParsed = [];
        if (!empty($connection['lang_map'])) {
            foreach (explode(',', $connection['lang_map']) as $pair) {
                $parts = explode(':', trim($pair));
                if (count($parts) === 2 && $parts[0] && $parts[1]) {
                    $langMapParsed[trim($parts[0])] = trim($parts[1]);
                }
            }
        }

        $allLanguages = Language::getLanguages(false);

        return $this->smFetch('connection_form.tpl', [
            'connection'          => $connection,
            'new_credentials'     => (!$idConn && $isMaster) ? SyncMasterApi::generateCredentials() : [],
            'is_edit'             => (bool)$idConn,
            'store_role'          => $storeRole,
            'is_master'           => $isMaster,
            'form_action'         => $listUrl . '&sm_action=save'
                . ($idConn ? '&id_connection=' . $idConn : ''),
            'link_list'           => $listUrl,
            'link_dashboard'      => $baseUrl,
            'errors'              => $errors,
            'all_categories'      => $allCategories,
            'selected_categories' => $selectedCategories,
            'all_languages'       => $allLanguages,
            'selected_lang_isos'  => $selectedLangIsos,
            'lang_map_parsed'     => $langMapParsed,
            'role_options'        => [
                ['value' => 'free',   'label' => $this->l('Free ID — La hija puede tener su propio catálogo')],
                ['value' => 'shared', 'label' => $this->l('Shared ID — Réplica exacta (mismo ID de producto)')],
            ],
        ]);
    }

    private function smConnectionSave($baseUrl, $idConnUrl)
    {
        $listUrl   = $baseUrl . '&sm_section=connections';
        $idConn    = (int)Tools::getValue('id_connection', $idConnUrl);
        $now       = date('Y-m-d H:i:s');
        $name      = trim(Tools::getValue('name', ''));
        $remoteUrl = rtrim(trim(Tools::getValue('remote_url', '')), '/');
        $apiKey    = trim(Tools::getValue('api_key', ''));
        $apiSecret = trim(Tools::getValue('api_secret', ''));
        $idMode    = Tools::getValue('id_mode', 'free');
        $errors    = [];

        if (!$name || !$remoteUrl || !$apiKey || !$apiSecret) {
            $errors[] = $this->l('Todos los campos obligatorios deben rellenarse.');
        } elseif (!Validate::isUrl($remoteUrl)) {
            $errors[] = $this->l('La URL no es válida. Debe incluir https://');
        }

        if ($errors) {
            return $this->smConnectionForm($baseUrl, $idConn, $errors);
        }

        // Categorías seleccionadas: array de enteros, guardar como CSV
        $catFilter = [];
        $postedCats = Tools::getValue('category_filter', []);
        if (is_array($postedCats)) {
            foreach ($postedCats as $cid) {
                $cid = (int)$cid;
                if ($cid > 0) {
                    $catFilter[] = $cid;
                }
            }
        }

        // Idiomas seleccionados: array de ISO codes, guardar como CSV
        $langFilter = [];
        $postedLangs = Tools::getValue('lang_filter', []);
        if (is_array($postedLangs)) {
            $availIsos = array_column(Language::getLanguages(false), 'iso_code');
            foreach ($postedLangs as $iso) {
                $iso = trim($iso);
                if ($iso && in_array($iso, $availIsos)) {
                    $langFilter[] = $iso;
                }
            }
        }

        $data = [
            'name'            => pSQL($name),
            'remote_url'      => pSQL($remoteUrl),
            'api_key'         => pSQL($apiKey),
            'api_secret'      => pSQL($apiSecret),
            'id_mode'         => in_array($idMode, ['shared','free']) ? $idMode : 'free',
            'active'          => Tools::getValue('active', 0)          ? 1 : 0,
            'sync_stock'      => Tools::getValue('sync_stock', 0)      ? 1 : 0,
            'sync_prices'     => Tools::getValue('sync_prices', 0)     ? 1 : 0,
            'sync_images'     => Tools::getValue('sync_images', 0)     ? 1 : 0,
            'delete_on_slave' => Tools::getValue('delete_on_slave', 0) ? 1 : 0,
            'category_filter' => pSQL(implode(',', $catFilter)),
            'lang_filter'     => pSQL(implode(',', $langFilter)),
            'lang_map'        => pSQL(Tools::getValue('lang_map', '')),
            'batch_size'      => max(10, min(200, (int)Tools::getValue('batch_size', 50))),
            'batch_delay'     => max(0,  min(30,  (int)Tools::getValue('batch_delay', 1))),
            'timeout'         => max(10, min(300, (int)Tools::getValue('timeout', 60))),
            'date_upd'        => $now,
        ];

        if ($idConn) {
            Db::getInstance()->update('sync_connections', $data, 'id_connection = ' . $idConn);
        } else {
            $data['date_add'] = $now;
            Db::getInstance()->insert('sync_connections', $data);
            $newId = (int)Db::getInstance()->Insert_ID();
            SyncMasterFieldConfig::initDefaults($newId);
        }

        Tools::redirectAdmin($listUrl);
        return '';
    }

    private function smFields($baseUrl, $action, $idConn)
    {
        $fieldsUrl = $baseUrl . '&sm_section=fields';

        if ($action === 'save' && $idConn) {
            $allFields = SyncMasterFieldConfig::getAllFields();
            $posted    = Tools::getAllValues();
            foreach ($allFields as $group => $groupData) {
                foreach ($groupData['fields'] as $field) {
                    $enabled    = isset($posted['field_' . $field]) ? 1 : 0;
                    $policy     = isset($posted['policy_' . $field]) ? $posted['policy_' . $field] : 'always';
                    $priceRule  = isset($posted['price_rule_' . $field]) ? $posted['price_rule_' . $field] : 'none';
                    $priceValue = (float)(isset($posted['price_value_' . $field]) ? $posted['price_value_' . $field] : 0);
                    if (!in_array($policy, ['always','if_untouched','never'])) { $policy = 'always'; }
                    if (!in_array($priceRule, ['none','percent_inc','percent_dec','fixed_inc','fixed_dec'])) {
                        $priceRule = 'none';
                    }
                    SyncMasterFieldConfig::saveField($idConn, $group, $field, [
                        'sync_enabled'     => $enabled,
                        'overwrite_policy' => $policy,
                        'price_rule'       => $priceRule,
                        'price_value'      => $priceValue,
                    ]);
                }
            }
            Tools::redirectAdmin($fieldsUrl . '&id_connection=' . $idConn);
            return '';
        }

        $connections = Db::getInstance()->executeS(
            'SELECT id_connection, name FROM `' . _DB_PREFIX_ . 'sync_connections`'
            . ' WHERE active = 1 ORDER BY name ASC'
        ) ?: [];

        if (!$idConn && !empty($connections)) {
            $idConn = (int)$connections[0]['id_connection'];
        }

        $allFields   = SyncMasterFieldConfig::getAllFields();
        $savedConfig = $idConn ? SyncMasterFieldConfig::getForConnection($idConn) : [];

        return $this->smFetch('fields_config.tpl', [
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
            'form_action'        => $fieldsUrl . '&sm_action=save&id_connection=' . $idConn,
            'link_dashboard'     => $baseUrl,
            'sm_base_url'        => $baseUrl,
            'store_role'         => Configuration::get('SYNCMASTER_ROLE') ?: self::ROLE_MASTER,
        ]);
    }

    private function smSync($baseUrl, $action, $idConn, $idJob)
    {
        $syncUrl = $baseUrl . '&sm_section=sync';
        $errors  = [];

        switch ($action) {
            case 'start':
                if (!$idConn) {
                    $errors[] = $this->l('Selecciona una conexión primero.');
                } else {
                    $conn = Db::getInstance()->getRow(
                        'SELECT * FROM `' . _DB_PREFIX_ . 'sync_connections`'
                        . ' WHERE id_connection = ' . $idConn . ' AND active = 1'
                    );
                    if (!$conn) {
                        $errors[] = $this->l('Conexión no encontrada o inactiva.');
                    } else {
                        $api   = new SyncMasterApi($conn['remote_url'], $conn['api_key'], $conn['api_secret']);
                        $shake = $api->handshake(['batch_size' => (int)$conn['batch_size']]);
                        if (!$shake['success']) {
                            $errors[] = $this->l('No se puede conectar con la tienda hija: ') . $shake['error'];
                        } else {
                            SyncMasterInitialJob::startOrResume($idConn);
                            Tools::redirectAdmin($syncUrl);
                            return '';
                        }
                    }
                }
                break;
            case 'pause':
                if ($idJob) { SyncMasterInitialJob::pauseJob($idJob); }
                Tools::redirectAdmin($syncUrl);
                return '';
            case 'cancel':
                if ($idJob) { SyncMasterInitialJob::cancelJob($idJob); }
                Tools::redirectAdmin($syncUrl);
                return '';
            case 'resume':
                if ($idJob) {
                    Db::getInstance()->update('sync_initial_job', [
                        'status'        => 'running',
                        'last_activity' => date('Y-m-d H:i:s'),
                    ], 'id_job = ' . $idJob);
                }
                Tools::redirectAdmin($syncUrl);
                return '';
        }

        $connections = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'sync_connections` WHERE active = 1 ORDER BY name ASC'
        ) ?: [];

        $jobs = Db::getInstance()->executeS(
            'SELECT j.*, c.name AS connection_name'
            . ' FROM `' . _DB_PREFIX_ . 'sync_initial_job` j'
            . ' LEFT JOIN `' . _DB_PREFIX_ . 'sync_connections` c ON c.id_connection = j.id_connection'
            . ' ORDER BY j.date_add DESC LIMIT 20'
        ) ?: [];

        // Detectar si hay jobs en ejecución para mostrar el aviso de no salir
        $runningJobs = array_filter($jobs, function ($j) {
            return $j['status'] === 'running';
        });

        return $this->smFetch('initial_sync.tpl', [
            'connections'  => $connections,
            'jobs'         => $jobs,
            'running_jobs' => $runningJobs,
            'errors'       => $errors,
            'master_stats' => [
                'products'   => (int)Db::getInstance()->getValue(
                    'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product`'),
                'categories' => (int)Db::getInstance()->getValue(
                    'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'category` WHERE id_category > 2'),
                'images'     => (int)Db::getInstance()->getValue(
                    'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'image`'),
            ],
            'ajax_url'       => $syncUrl,
            'store_role'     => Configuration::get('SYNCMASTER_ROLE') ?: self::ROLE_MASTER,
            'is_master'      => in_array(
                Configuration::get('SYNCMASTER_ROLE') ?: self::ROLE_MASTER,
                [self::ROLE_MASTER, self::ROLE_BOTH]
            ),
            'link_dashboard' => $baseUrl,
            'sm_base_url'    => $baseUrl,
        ]);
    }

    private function smLogs($baseUrl, $action, $idConn)
    {
        $logsUrl = $baseUrl . '&sm_section=logs';
        $status  = Tools::getValue('status', '');

        switch ($action) {
            case 'clear':
                Db::getInstance()->execute('TRUNCATE `' . _DB_PREFIX_ . 'sync_log`');
                Tools::redirectAdmin($logsUrl);
                return '';
            case 'clear_queue':
                Db::getInstance()->execute(
                    'DELETE FROM `' . _DB_PREFIX_ . 'sync_queue` WHERE status = \'failed\''
                );
                Tools::redirectAdmin($logsUrl);
                return '';
            case 'retry_all':
                SyncMasterQueue::retryAll();
                Tools::redirectAdmin($logsUrl);
                return '';
        }

        $where = '1=1';
        if ($idConn) { $where .= ' AND l.id_connection = ' . (int)$idConn; }
        if ($status && in_array($status, ['success','warning','error'])) {
            $where .= ' AND l.status = \'' . pSQL($status) . '\'';
        }

        $logs = Db::getInstance()->executeS(
            'SELECT l.*, c.name AS connection_name'
            . ' FROM `' . _DB_PREFIX_ . 'sync_log` l'
            . ' LEFT JOIN `' . _DB_PREFIX_ . 'sync_connections` c ON c.id_connection = l.id_connection'
            . ' WHERE ' . $where . ' ORDER BY l.date_add DESC LIMIT 200'
        ) ?: [];

        $connections = Db::getInstance()->executeS(
            'SELECT id_connection, name FROM `' . _DB_PREFIX_ . 'sync_connections` ORDER BY name'
        ) ?: [];

        $queueFailed = Db::getInstance()->executeS(
            'SELECT q.*, c.name AS connection_name'
            . ' FROM `' . _DB_PREFIX_ . 'sync_queue` q'
            . ' LEFT JOIN `' . _DB_PREFIX_ . 'sync_connections` c ON c.id_connection = q.id_connection'
            . ' WHERE q.status = \'failed\' ORDER BY q.date_add DESC LIMIT 50'
        ) ?: [];

        return $this->smFetch('logs.tpl', [
            'logs'           => $logs,
            'connections'    => $connections,
            'queue_stats'    => SyncMasterQueue::getStats(),
            'queue_failed'   => $queueFailed,
            'filter_conn'    => $idConn,
            'filter_status'  => $status,
            'current_url'    => $logsUrl,
            'link_dashboard' => $baseUrl,
            'sm_base_url'    => $baseUrl,
        ]);
    }
}
