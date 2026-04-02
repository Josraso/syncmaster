<?php
/**
 * SyncMaster Pro — Cron: procesar cola de reintentos
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

// Procesar la cola (máximo 50 items por ejecución)
$stats = SyncMasterQueue::processQueue(50);

// Limpiar items viejos completados
SyncMasterQueue::cleanup(7);

// Limpiar logs viejos
$daysLog = (int)Configuration::get('SYNCMASTER_LOG_RETENTION') ?: 30;
SyncMasterLogger::cleanup($daysLog);

$message = date('Y-m-d H:i:s') . ' | Queue: '
    . 'OK=' . $stats['processed']
    . ' FAIL=' . $stats['failed']
    . ' SKIP=' . $stats['skipped'];

if (php_sapi_name() === 'cli') {
    echo $message . PHP_EOL;
} else {
    header('Content-Type: text/plain');
    echo $message;
}
