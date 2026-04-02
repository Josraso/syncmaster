<?php
/**
 * SyncMaster Pro — Cron: pull periódico (reconciliación)
 * Se ejecuta en el SLAVE para pedir al master los cambios desde el último sync.
 *
 * Crontab (cada 15 minutos):
 * *\/15 * * * * php /ruta/a/prestashop/modules/syncmaster/cron/sync.php
 *
 * O vía URL:
 * https://tutienda.com/modules/syncmaster/cron/sync.php?token=TU_TOKEN
 */

$psRoot = realpath(dirname(__FILE__) . '/../../../');
if (!file_exists($psRoot . '/config/config.inc.php')) {
    for ($i = 0; $i < 5; $i++) {
        $psRoot = realpath($psRoot . '/../');
        if (file_exists($psRoot . '/config/config.inc.php')) {
            break;
        }
    }
}

if (!file_exists($psRoot . '/config/config.inc.php')) {
    die('ERROR: No se pudo localizar PrestaShop.');
}

define('_PS_ADMIN_DIR_', $psRoot . '/admin');
require_once $psRoot . '/config/config.inc.php';
require_once _PS_MODULE_DIR_ . 'syncmaster/syncmaster.php';

if (php_sapi_name() !== 'cli') {
    $token = Tools::getValue('token', '');
    if ($token !== Configuration::get('SYNCMASTER_CRON_TOKEN')) {
        header('HTTP/1.1 403 Forbidden');
        die('Token inválido.');
    }
}

// Solo ejecutar en modo slave o both
$role = Configuration::get('SYNCMASTER_ROLE');
if (!in_array($role, [SyncMaster::ROLE_SLAVE, SyncMaster::ROLE_BOTH])) {
    die('Esta tienda no es slave, el pull no aplica.');
}

// Para cada conexión activa en modo slave, pedir el estado al master
// (El pull completo requeriría que el master tenga un endpoint /changes?since=X)
// Por ahora, procesar la cola de reintentos pendientes
$stats = SyncMasterQueue::processQueue(100);

$message = date('Y-m-d H:i:s') . ' | Pull sync: '
    . 'OK=' . $stats['processed']
    . ' FAIL=' . $stats['failed'];

if (php_sapi_name() === 'cli') {
    echo $message . PHP_EOL;
} else {
    header('Content-Type: text/plain');
    echo $message;
}
