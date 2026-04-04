<?php
/**
 * SyncMaster Pro — Cron: procesar cola de reintentos + jobs de sync inicial
 *
 * Configurar en crontab (cada minuto):
 * * * * * * php /ruta/a/prestashop/modules/syncmaster/cron/retry_queue.php
 *
 * O vía URL (con token de seguridad):
 * https://tutienda.com/modules/syncmaster/cron/retry_queue.php?token=TU_TOKEN
 */

// Localizar PrestaShop
$psRoot = realpath(dirname(__FILE__) . '/../../../');
if (!file_exists($psRoot . '/config/config.inc.php')) {
    // Buscar subiendo niveles (en caso de instalación no estándar)
    for ($i = 0; $i < 5; $i++) {
        $psRoot = realpath($psRoot . '/../');
        if (file_exists($psRoot . '/config/config.inc.php')) {
            break;
        }
    }
}

if (!file_exists($psRoot . '/config/config.inc.php')) {
    die('ERROR: No se pudo localizar la instalación de PrestaShop.');
}

define('_PS_ADMIN_DIR_', $psRoot . '/admin');

require_once $psRoot . '/config/config.inc.php';
require_once _PS_MODULE_DIR_ . 'syncmaster/syncmaster.php';

// Validar token de seguridad si se accede por HTTP
if (php_sapi_name() !== 'cli') {
    $token = Tools::getValue('token', '');
    if ($token !== Configuration::get('SYNCMASTER_CRON_TOKEN')) {
        header('HTTP/1.1 403 Forbidden');
        die('Token inválido.');
    }
}

// Asegurarse de que las columnas de migración existen
// (sin esto, delete_on_slave u otros campos nuevos no funcionarían en cron)
$_pCron = _DB_PREFIX_;
foreach ([
    'sync_connections' => [
        'delete_on_slave' => "ALTER TABLE `{$_pCron}sync_connections`
            ADD COLUMN `delete_on_slave` TINYINT(1) NOT NULL DEFAULT 1 AFTER `sync_images`",
        'category_filter' => "ALTER TABLE `{$_pCron}sync_connections`
            ADD COLUMN `category_filter` TEXT DEFAULT NULL AFTER `delete_on_slave`",
        'lang_filter'     => "ALTER TABLE `{$_pCron}sync_connections`
            ADD COLUMN `lang_filter` VARCHAR(255) DEFAULT NULL AFTER `category_filter`",
        'lang_map'        => "ALTER TABLE `{$_pCron}sync_connections`
            ADD COLUMN `lang_map` VARCHAR(500) DEFAULT NULL AFTER `lang_filter`",
    ],
    'sync_initial_job' => [
        'skip_images' => "ALTER TABLE `{$_pCron}sync_initial_job`
            ADD COLUMN `skip_images` TINYINT(1) NOT NULL DEFAULT 0 AFTER `batch_size`",
    ],
] as $_cronTable => $_cronCols) {
    $_cronExisting = [];
    $_cronRows = Db::getInstance()->executeS(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . pSQL($_pCron . $_cronTable) . "'"
    );
    if ($_cronRows) {
        foreach ($_cronRows as $_r) {
            $_cronExisting[] = strtolower($_r['COLUMN_NAME']);
        }
    }
    foreach ($_cronCols as $_col => $_sql) {
        if (!in_array(strtolower($_col), $_cronExisting)) {
            Db::getInstance()->execute($_sql);
        }
    }
}
unset($_pCron, $_cronTable, $_cronCols, $_cronExisting, $_cronRows, $_col, $_sql, $_r);

// =========================================================================
// MUTEX ligero vía BD: evitar doble proceso del mismo job
// (flock() no es fiable en todos los hostings; usamos last_activity en DB)
// =========================================================================
// Nada que hacer aquí — el control de concurrencia se hace dentro de
// processNextBatch() comparando last_activity (< 30s = otro proceso activo).


// =========================================================================
// 1. PROCESAR COLA DE REINTENTOS (eventos en tiempo real)
// =========================================================================
$stats = SyncMasterQueue::processQueue(50);

// Limpiar items viejos completados
SyncMasterQueue::cleanup(7);

// Limpiar logs viejos
$daysLog = (int)Configuration::get('SYNCMASTER_LOG_RETENTION') ?: 30;
SyncMasterLogger::cleanup($daysLog);

// =========================================================================
// 2. PROCESAR JOBS DE SYNC INICIAL PENDIENTES (solo en master)
// =========================================================================
$role          = Configuration::get('SYNCMASTER_ROLE') ?: 'master';
$initialResult = [];

if (in_array($role, ['master', 'both'])) {
    if (!class_exists('SyncMasterInitialJob')) {
        require_once _PS_MODULE_DIR_ . 'syncmaster/classes/SyncMasterInitialJob.php';
    }

    // Recogemos jobs en running. Si last_activity fue hace < 30s otro proceso
    // acaba de tocarlos — los saltamos para evitar proceso doble.
    $runningJobs = Db::getInstance()->executeS(
        'SELECT id_job FROM `' . _DB_PREFIX_ . 'sync_initial_job`
         WHERE status = \'running\'
           AND (last_activity IS NULL OR last_activity <= DATE_SUB(NOW(), INTERVAL 30 SECOND))
         ORDER BY last_activity ASC'
    ) ?: [];

    $cronStart = time();
    foreach ($runningJobs as $jobRow) {
        $idJob = (int)$jobRow['id_job'];
        // Procesar lotes hasta agotar ~50 segundos del ciclo de cron
        while ((time() - $cronStart) < 50) {
            try {
                $batchResult = SyncMasterInitialJob::processNextBatch($idJob);
                $initialResult[] = $batchResult;

                if (!empty($batchResult['done'])) {
                    break; // Job terminado
                }
                if (!empty($batchResult['paused'])) {
                    break; // Error de aplicación → pausado, siguiente cron reintentará
                }
                if (!empty($batchResult['retry'])) {
                    break; // Error de red → salir del while, siguiente cron reintentará el mismo lote
                }
            } catch (Exception $batchEx) {
                $initialResult[] = ['error' => $batchEx->getMessage()];
                break; // Siguiente cron reintentará
            }
        }

        // Si ya llevamos ≥50s en total, parar aunque queden más jobs
        if ((time() - $cronStart) >= 50) {
            break;
        }
    }
}

// =========================================================================
// SALIDA
// =========================================================================
$batchCount  = count($initialResult);
$batchErrors = array_filter($initialResult, function($r){ return !empty($r['error']); });

// Contar jobs running en BD para debug
$totalRunning = (int)Db::getInstance()->getValue(
    'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'sync_initial_job` WHERE status = \'running\''
);

$message = date('Y-m-d H:i:s') . ' | Queue: '
    . 'OK=' . $stats['processed']
    . ' FAIL=' . $stats['failed']
    . ' SKIP=' . $stats['skipped']
    . ' | InitialSync: jobs_running=' . $totalRunning
    . ' batches_processed=' . $batchCount
    . ($batchErrors ? ' errors=' . count($batchErrors) : '');

if (php_sapi_name() === 'cli') {
    echo $message . PHP_EOL;
} else {
    header('Content-Type: text/plain');
    echo $message;
}
