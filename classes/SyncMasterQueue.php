<?php
/**
 * SyncMasterQueue
 * Cola de eventos con reintentos exponenciales.
 * pending → processing → done | failed
 *
 * Tiempos de backoff: 1m → 5m → 15m → 1h → 6h → 24h
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Cargar dependencias explícitamente (este fichero puede cargarse sin el autoloader)
$_smQueueDir = dirname(__FILE__) . '/';
foreach (['SyncMasterHelpers', 'SyncMasterVersionCompat', 'SyncMasterSerializer', 'SyncMasterApi'] as $_cls) {
    if (!class_exists($_cls) && file_exists($_smQueueDir . $_cls . '.php')) {
        require_once $_smQueueDir . $_cls . '.php';
    }
}
unset($_smQueueDir, $_cls);

class SyncMasterQueue
{
    /** Segundos entre reintentos: 1m, 5m, 15m, 1h, 6h, 24h */
    const RETRY_DELAYS = [60, 300, 900, 3600, 21600, 86400];

    const STATUS_PENDING    = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_DONE       = 'done';
    const STATUS_FAILED     = 'failed';

    // =========================================================================
    // ENCOLAR EVENTOS
    // =========================================================================

    /**
     * Encola un evento de entidad para todas las conexiones master activas.
     *
     * @param string $entityType  'product' | 'category' | 'attribute_group' | ...
     * @param int    $entityId
     * @param string $action      'create' | 'update' | 'delete'
     */
    public static function enqueue($entityType, $entityId, $action)
    {
        $connections = self::getActiveConnections();
        if (empty($connections)) {
            return;
        }

        foreach ($connections as $connection) {
            $idConn = (int)$connection['id_connection'];

            // Si hay un delete pendiente para esta entidad, no añadir más eventos
            if ($action !== 'delete') {
                $pending = self::findPending($idConn, $entityType, (int)$entityId);
                if ($pending) {
                    continue; // Ya en cola, el worker lo procesará con los datos más recientes
                }
            } else {
                // Delete: cancelar cualquier pending anterior y añadir el delete
                self::cancelPending($idConn, $entityType, (int)$entityId);
            }

            Db::getInstance()->insert('sync_queue', [
                'id_connection' => $idConn,
                'entity_type'   => pSQL($entityType),
                'entity_id'     => (int)$entityId,
                'action'        => pSQL($action),
                'payload'       => '',  // Se serializa en el worker (lazy)
                'status'        => self::STATUS_PENDING,
                'attempts'      => 0,
                'next_retry'    => date('Y-m-d H:i:s'),
                'date_add'      => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * Encola una actualización de stock rápida (payload pre-calculado).
     */
    public static function enqueueStock($idProduct, $idProductAttribute, $quantity)
    {
        $connections = self::getActiveConnections('sync_stock');
        if (empty($connections)) {
            return;
        }

        $payload = json_encode(
            SyncMasterSerializer::serializeStock(
                (int)$idProduct, (int)$idProductAttribute, (int)$quantity
            )
        );

        foreach ($connections as $connection) {
            Db::getInstance()->insert('sync_queue', [
                'id_connection' => (int)$connection['id_connection'],
                'entity_type'   => 'stock',
                'entity_id'     => (int)$idProduct,
                'action'        => 'update',
                'payload'       => pSQL($payload, true),
                'status'        => self::STATUS_PENDING,
                'attempts'      => 0,
                'next_retry'    => date('Y-m-d H:i:s'),
                'date_add'      => date('Y-m-d H:i:s'),
            ]);
        }
    }

    // =========================================================================
    // WORKER: PROCESAR COLA
    // =========================================================================

    /**
     * Procesa hasta $limit items pendientes de la cola.
     * Llamado desde cron o desde el hook de admin.
     *
     * @param  int   $limit  Máximo de items a procesar en esta ejecución
     * @return array ['processed'=>int, 'failed'=>int, 'skipped'=>int]
     */
    public static function processQueue($limit = 20)
    {
        $stats = ['processed' => 0, 'failed' => 0, 'skipped' => 0];

        // Asegurar que las columnas de migración existen (puede que upgradeSchema aún no haya corrido)
        $p = _DB_PREFIX_;
        $existingCols = array_column(
            Db::getInstance()->executeS(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = \'' . pSQL($p . 'sync_connections') . '\''
            ) ?: [],
            'COLUMN_NAME'
        );
        $existingCols = array_map('strtolower', $existingCols);
        $colMigrations = [
            'delete_on_slave' => "ALTER TABLE `{$p}sync_connections` ADD COLUMN `delete_on_slave` TINYINT(1) NOT NULL DEFAULT 1 AFTER `sync_images`",
            'category_filter' => "ALTER TABLE `{$p}sync_connections` ADD COLUMN `category_filter` TEXT DEFAULT NULL AFTER `delete_on_slave`",
            'lang_filter'     => "ALTER TABLE `{$p}sync_connections` ADD COLUMN `lang_filter` VARCHAR(255) DEFAULT NULL AFTER `category_filter`",
        ];
        foreach ($colMigrations as $col => $sql) {
            if (!in_array($col, $existingCols)) {
                Db::getInstance()->execute($sql);
            }
        }
        unset($existingCols, $colMigrations, $col, $sql, $p);

        $items = Db::getInstance()->executeS(
            'SELECT q.*, c.remote_url, c.api_key, c.api_secret, c.timeout, c.id_mode,
                    COALESCE(c.delete_on_slave, 1) AS delete_on_slave,
                    COALESCE(c.lang_filter, \'\') AS lang_filter
             FROM `' . _DB_PREFIX_ . 'sync_queue` q
             INNER JOIN `' . _DB_PREFIX_ . 'sync_connections` c
                 ON c.id_connection = q.id_connection AND c.active = 1
             WHERE q.status = \'' . self::STATUS_PENDING . '\'
               AND q.next_retry <= NOW()
             ORDER BY q.date_add ASC
             LIMIT ' . (int)$limit
        );

        if (empty($items)) {
            return $stats;
        }

        foreach ($items as $item) {
            // Marcar como processing para evitar doble proceso en paralelo
            Db::getInstance()->update('sync_queue',
                ['status' => self::STATUS_PROCESSING],
                'id_queue = ' . (int)$item['id_queue']
                . ' AND status = \'' . self::STATUS_PENDING . '\''
            );

            // Verificar que efectivamente lo marcamos nosotros (concurrencia)
            $check = Db::getInstance()->getValue(
                'SELECT status FROM `' . _DB_PREFIX_ . 'sync_queue`
                 WHERE id_queue = ' . (int)$item['id_queue']
            );
            if ($check !== self::STATUS_PROCESSING) {
                $stats['skipped']++;
                continue;
            }

            // Si delete_on_slave = 0 para esta conexión, descartar silenciosamente el delete
            if ($item['action'] === 'delete' && !(int)$item['delete_on_slave']) {
                Db::getInstance()->update('sync_queue',
                    ['status' => self::STATUS_DONE, 'date_done' => date('Y-m-d H:i:s')],
                    'id_queue = ' . (int)$item['id_queue']
                );
                $stats['skipped']++;
                continue;
            }

            $start = microtime(true);

            try {
                $result = self::processItem($item);

                if ($result['success']) {
                    $durationMs = (int)((microtime(true) - $start) * 1000);
                    Db::getInstance()->update('sync_queue', [
                        'status'    => self::STATUS_DONE,
                        'date_done' => date('Y-m-d H:i:s'),
                    ], 'id_queue = ' . (int)$item['id_queue']);

                    // Actualizar last_sync de la conexión
                    Db::getInstance()->update('sync_connections', [
                        'last_sync'  => date('Y-m-d H:i:s'),
                        'last_error' => null,
                    ], 'id_connection = ' . (int)$item['id_connection']);

                    SyncMasterLogger::log(
                        (int)$item['id_connection'],
                        $item['entity_type'],
                        (int)$item['entity_id'],
                        $item['action'],
                        'success',
                        null,
                        $durationMs
                    );

                    $stats['processed']++;
                } else {
                    self::handleFailure($item, (isset($result['error']) ? $result['error'] : 'Error desconocido'));
                    $stats['failed']++;
                }
            } catch (Exception $e) {
                self::handleFailure($item, $e->getMessage());
                $stats['failed']++;
            }
        }

        return $stats;
    }

    // =========================================================================
    // PROCESO DE UN ITEM
    // =========================================================================

    private static function processItem(array $item)
    {
        // Construir payload si está vacío (lazy serialization)
        $payload = $item['payload'];
        if (empty($payload)) {
            $payload = self::buildPayload($item);
            if (!$payload) {
                return ['success' => false, 'error' => 'No se pudo serializar la entidad ' . $item['entity_type'] . '#' . $item['entity_id']];
            }
            // Actualizar el payload en BD para no tener que re-serializarlo en reintentos
            Db::getInstance()->update('sync_queue', [
                'payload' => pSQL($payload, true),
            ], 'id_queue = ' . (int)$item['id_queue']);
        }

        // Aplicar regla de precio si es un producto
        $payloadArray = json_decode($payload, true);
        if ($payloadArray && (isset($payloadArray['entity']) ? $payloadArray['entity'] : '') === 'product') {
            $payloadArray = SyncMasterPriceRule::apply($payloadArray, (int)$item['id_connection']);
            $payload = json_encode($payloadArray);
        }

        // Enviar al slave
        $api = new SyncMasterApi(
            $item['remote_url'],
            $item['api_key'],
            $item['api_secret']
        );

        return $api->sendPayload($payload, (int)(isset($item['timeout']) ? $item['timeout'] : 30));
    }

    /**
     * Serializa el payload de un item que fue encolado sin él (lazy).
     */
    private static function buildPayload(array $item)
    {
        $fieldConfig = SyncMasterFieldConfig::getForConnection((int)$item['id_connection']);
        $langFilter  = isset($item['lang_filter']) ? $item['lang_filter'] : '';

        switch ($item['entity_type']) {
            case 'product':
                if ($item['action'] === 'delete') {
                    return json_encode([
                        'sync_version' => SyncMasterSerializer::PAYLOAD_VERSION,
                        'entity'       => 'product',
                        'action'       => 'delete',
                        'master_id'    => (int)$item['entity_id'],
                        'timestamp'    => date('c'),
                    ]);
                }
                $data = SyncMasterSerializer::serializeProduct(
                    (int)$item['entity_id'],
                    $fieldConfig,
                    $langFilter
                );
                if (!$data) return null;
                $data['action'] = $item['action'];
                return json_encode($data);

            case 'category':
                $data = SyncMasterSerializer::serializeCategory((int)$item['entity_id'], $langFilter);
                if (!$data) return null;
                $data['action'] = $item['action'];
                return json_encode($data);

            case 'stock':
                // El stock siempre viene con payload
                return (isset($item['payload']) && $item['payload'] ? $item['payload'] : null);

            default:
                // Para atributos, características, etc. se podría ampliar aquí
                return null;
        }
    }

    // =========================================================================
    // GESTIÓN DE FALLOS Y REINTENTOS EXPONENCIALES
    // =========================================================================

    private static function handleFailure(array $item, $errorMsg)
    {
        $attempts  = (int)$item['attempts'] + 1;
        $maxRetries = count(self::RETRY_DELAYS);

        if ($attempts >= $maxRetries) {
            // Agotados todos los reintentos → fallido definitivo
            Db::getInstance()->update('sync_queue', [
                'status'    => self::STATUS_FAILED,
                'attempts'  => $attempts,
                'error_msg' => pSQL(substr($errorMsg, 0, 500)),
                'next_retry' => null,
            ], 'id_queue = ' . (int)$item['id_queue']);

            Db::getInstance()->update('sync_connections', [
                'last_error' => pSQL(substr($errorMsg, 0, 500)),
            ], 'id_connection = ' . (int)$item['id_connection']);
        } else {
            $delaySeconds = (isset(self::RETRY_DELAYS[$attempts - 1]) ? self::RETRY_DELAYS[$attempts - 1] : 86400);
            $nextRetry    = date('Y-m-d H:i:s', time() + $delaySeconds);

            Db::getInstance()->update('sync_queue', [
                'status'     => self::STATUS_PENDING,
                'attempts'   => $attempts,
                'error_msg'  => pSQL(substr($errorMsg, 0, 500)),
                'next_retry' => $nextRetry,
            ], 'id_queue = ' . (int)$item['id_queue']);
        }

        SyncMasterLogger::log(
            (int)$item['id_connection'],
            $item['entity_type'],
            (int)$item['entity_id'],
            $item['action'],
            'error',
            'Intento ' . $attempts . ': ' . $errorMsg
        );
    }

    // =========================================================================
    // CONSULTAS AUXILIARES
    // =========================================================================

    private static function getActiveConnections($requiredFlag = null)
    {
        $where = 'active = 1';
        if ($requiredFlag) {
            $where .= ' AND `' . pSQL($requiredFlag) . '` = 1';
        }
        return Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'sync_connections` WHERE ' . $where
        ) ?: [];
    }

    private static function findPending($idConnection, $entityType, $entityId)
    {
        return Db::getInstance()->getRow(
            'SELECT id_queue FROM `' . _DB_PREFIX_ . 'sync_queue`
             WHERE id_connection = ' . (int)$idConnection . '
               AND entity_type = \'' . pSQL($entityType) . '\'
               AND entity_id   = ' . (int)$entityId . '
               AND status IN (\'' . self::STATUS_PENDING . '\', \'' . self::STATUS_PROCESSING . '\')'
        );
    }

    private static function cancelPending($idConnection, $entityType, $entityId)
    {
        Db::getInstance()->update('sync_queue', [
            'status'    => self::STATUS_FAILED,
            'error_msg' => 'Cancelado: entidad eliminada antes de enviarse.',
        ],
            'id_connection = ' . (int)$idConnection . '
             AND entity_type = \'' . pSQL($entityType) . '\'
             AND entity_id = ' . (int)$entityId . '
             AND status IN (\'' . self::STATUS_PENDING . '\', \'' . self::STATUS_PROCESSING . '\')'
        );
    }

    // =========================================================================
    // ESTADÍSTICAS Y MANTENIMIENTO
    // =========================================================================

    public static function getStats()
    {
        $row = Db::getInstance()->getRow(
            'SELECT
                SUM(status = \'' . self::STATUS_PENDING    . '\') AS pending,
                SUM(status = \'' . self::STATUS_PROCESSING . '\') AS processing,
                SUM(status = \'' . self::STATUS_DONE       . '\') AS done,
                SUM(status = \'' . self::STATUS_FAILED     . '\') AS failed
             FROM `' . _DB_PREFIX_ . 'sync_queue`'
        );
        return $row ?: ['pending' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0];
    }

    /** Elimina items completados con más de $daysOld días de antigüedad */
    public static function cleanup($daysOld = 7)
    {
        Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'sync_queue`
             WHERE status IN (\'' . self::STATUS_DONE . '\', \'' . self::STATUS_FAILED . '\')
               AND date_add < DATE_SUB(NOW(), INTERVAL ' . (int)$daysOld . ' DAY)'
        );
    }

    /** Reintentar todos los items fallidos (reset a pending) */
    public static function retryAll()
    {
        Db::getInstance()->update('sync_queue', [
            'status'     => self::STATUS_PENDING,
            'attempts'   => 0,
            'next_retry' => date('Y-m-d H:i:s'),
            'error_msg'  => null,
        ], 'status = \'' . self::STATUS_FAILED . '\'');
    }
}
