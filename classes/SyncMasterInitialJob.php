<?php
/**
 * SyncMasterInitialJob
 * Gestiona la sincronización inicial masiva por lotes con punto de control.
 * Funciona tanto en el master (exporta) como en el slave (importa).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SyncMasterInitialJob
{
    const PHASE_CATEGORIES    = 'categories';
    const PHASE_MANUFACTURERS = 'manufacturers';
    const PHASE_ATTRIBUTES    = 'attributes';
    const PHASE_FEATURES      = 'features';
    const PHASE_PRODUCTS      = 'products';
    const PHASE_IMAGES        = 'images';
    const PHASE_VERIFICATION  = 'verification';

    /** Orden de fases */
    const PHASES = [
        self::PHASE_CATEGORIES,
        self::PHASE_MANUFACTURERS,
        self::PHASE_ATTRIBUTES,
        self::PHASE_FEATURES,
        self::PHASE_PRODUCTS,
        self::PHASE_IMAGES,
        self::PHASE_VERIFICATION,
    ];

    // =========================================================================
    // MASTER: INICIAR / REANUDAR JOB
    // =========================================================================

    /**
     * Crea un nuevo job de sync inicial para una conexión.
     * Si ya existe uno pausado, lo reanuda.
     */
    public static function startOrResume($idConnection)
    {
        // Comprobar si hay un job pausado o en curso
        $existing = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'sync_initial_job`
             WHERE id_connection = ' . (int)$idConnection . '
             AND status IN (\'pending\', \'running\', \'paused\')
             ORDER BY date_add DESC'
        );

        if ($existing) {
            // Reanudar desde donde se quedó
            Db::getInstance()->update('sync_initial_job', [
                'status'        => 'running',
                'last_activity' => date('Y-m-d H:i:s'),
            ], 'id_job = ' . (int)$existing['id_job']);
            return (int)$existing['id_job'];
        }

        // Contar entidades del master para calcular lotes
        $connection  = self::getConnection($idConnection);
        $batchSize   = (int)(isset($connection['batch_size']) ? $connection['batch_size'] : 50);
        $totalProds  = self::countProducts();
        $totalBatches = ceil($totalProds / $batchSize)
            + ceil(self::countCategories() / $batchSize)
            + ceil(self::countManufacturers() / $batchSize)
            + 2; // attributes + features (se cuenta al llegar)

        Db::getInstance()->insert('sync_initial_job', [
            'id_connection'   => (int)$idConnection,
            'status'          => 'running',
            'phase'           => self::PHASE_CATEGORIES,
            'total_items'     => $totalProds,
            'total_batches'   => (int)$totalBatches,
            'current_batch'   => 0,
            'processed_items' => 0,
            'failed_items'    => 0,
            'batch_size'      => $batchSize,
            'progress_pct'    => 0,
            'started_at'      => date('Y-m-d H:i:s'),
            'last_activity'   => date('Y-m-d H:i:s'),
            'date_add'        => date('Y-m-d H:i:s'),
        ]);

        return (int)Db::getInstance()->Insert_ID();
    }

    // =========================================================================
    // MASTER: PROCESAR UN LOTE
    // =========================================================================

    /**
     * Procesa el siguiente lote del job.
     * Llamado de forma iterativa desde AJAX o cron.
     *
     * @return array ['done'=>bool, 'progress'=>int, 'phase'=>str, 'batch'=>int, 'error'=>str|null]
     */
    public static function processNextBatch($idJob)
    {
        $job = Db::getInstance()->getRow(
            'SELECT j.*, c.remote_url, c.api_key, c.api_secret, c.batch_size, c.timeout, c.batch_delay
             FROM `' . _DB_PREFIX_ . 'sync_initial_job` j
             INNER JOIN `' . _DB_PREFIX_ . 'sync_connections` c
                 ON c.id_connection = j.id_connection
             WHERE j.id_job = ' . (int)$idJob
        );

        if (!$job) {
            return ['done' => true, 'error' => 'Job no encontrado'];
        }

        if ($job['status'] === 'done') {
            return ['done' => true, 'progress' => 100, 'phase' => $job['phase']];
        }

        // Si el job está pausado/fallido, auto-reanudar para que el admin
        // pueda reintentar con un solo clic (el botón "▶ Reanudar")
        if ($job['status'] === 'paused' || $job['status'] === 'failed') {
            Db::getInstance()->update('sync_initial_job', [
                'status'        => 'running',
                'error_log'     => null,
                'last_activity' => date('Y-m-d H:i:s'),
            ], 'id_job = ' . (int)$idJob);
            $job['status'] = 'running';
        }

        // Actualizar actividad
        Db::getInstance()->update('sync_initial_job', [
            'last_activity' => date('Y-m-d H:i:s'),
        ], 'id_job = ' . (int)$idJob);

        $phase     = $job['phase'];
        $batchSize = (int)$job['batch_size'];
        $offset    = (int)$job['processed_items']; // dentro de la fase actual

        $start = microtime(true);

        // Obtener el lote de entidades según la fase
        $items = self::getItemsBatch($phase, $offset, $batchSize);

        // Si no hay más items en esta fase → pasar a la siguiente
        if (empty($items)) {
            $nextPhase = self::getNextPhase($phase);

            if ($nextPhase === null) {
                // Todas las fases completadas
                Db::getInstance()->update('sync_initial_job', [
                    'status'       => 'done',
                    'progress_pct' => 100,
                    'completed_at' => date('Y-m-d H:i:s'),
                ], 'id_job = ' . (int)$idJob);

                return ['done' => true, 'progress' => 100, 'phase' => $phase];
            }

            // Siguiente fase: resetear processed_items
            Db::getInstance()->update('sync_initial_job', [
                'phase'           => $nextPhase,
                'processed_items' => 0,
            ], 'id_job = ' . (int)$idJob);

            return [
                'done'     => false,
                'progress' => self::calcProgress($job, $phase),
                'phase'    => $nextPhase,
                'batch'    => (int)$job['current_batch'],
                'message'  => 'Fase completada: ' . $phase . ' → ' . $nextPhase,
            ];
        }

        // Serializar el lote
        $serialized = self::serializeBatch($phase, $items, $job);

        // Enviar al slave
        $api    = new SyncMasterApi($job['remote_url'], $job['api_key'], $job['api_secret']);
        $result = $api->sendBatch(
            $serialized,
            $phase,
            (int)$job['current_batch'] + 1,
            $idJob,
            (int)$job['timeout']
        );

        $durationMs = (int)((microtime(true) - $start) * 1000);
        $itemsSent  = count($items);

        // Leer el ok/fail real que devuelve el slave (207 = parcial, 200 = todo ok)
        if ($result['success']) {
            $resp      = isset($result['response']) ? $result['response'] : [];
            $itemsOk   = isset($resp['ok'])     ? (int)$resp['ok']     : $itemsSent;
            $itemsFail = isset($resp['failed']) ? (int)$resp['failed'] : 0;
        } else {
            $itemsOk   = 0;
            $itemsFail = $itemsSent;
        }

        // Log del lote
        Db::getInstance()->insert('sync_initial_batch', [
            'id_job'       => (int)$idJob,
            'batch_number' => (int)$job['current_batch'] + 1,
            'phase'        => pSQL($phase),
            'offset'       => $offset,
            'limit_size'   => $batchSize,
            'items_sent'   => $itemsSent,
            'items_ok'     => $itemsOk,
            'items_failed' => $itemsFail,
            'status'       => $result['success'] ? 'done' : 'failed',
            'duration_ms'  => $durationMs,
            'error_msg'    => $result['success'] ? null : pSQL(substr((isset($result['error']) ? $result['error'] : ''), 0, 500)),
            'date_add'     => date('Y-m-d H:i:s'),
        ]);

        if (!$result['success']) {
            // Fallo en el lote → pausar el job para que el admin decida
            Db::getInstance()->update('sync_initial_job', [
                'status'       => 'paused',
                'failed_items' => (int)$job['failed_items'] + $itemsFail,
                'error_log'    => pSQL('Lote ' . ((int)$job['current_batch'] + 1) . ': ' . $result['error']),
            ], 'id_job = ' . (int)$idJob);

            return [
                'done'    => false,
                'error'   => $result['error'],
                'phase'   => $phase,
                'batch'   => (int)$job['current_batch'] + 1,
                'paused'  => true,
            ];
        }

        // Actualizar progreso
        $newProcessed = $offset + $itemsSent;
        $newBatch     = (int)$job['current_batch'] + 1;
        $progress     = self::calcProgress(array_merge($job, [
            'processed_items' => $newProcessed,
        ]), $phase);

        Db::getInstance()->update('sync_initial_job', [
            'current_batch'   => $newBatch,
            'processed_items' => $newProcessed,
            'progress_pct'    => $progress,
            'last_activity'   => date('Y-m-d H:i:s'),
        ], 'id_job = ' . (int)$idJob);

        // Delay entre lotes si está configurado
        if (!empty($job['batch_delay']) && (int)$job['batch_delay'] > 0) {
            sleep((int)$job['batch_delay']);
        }

        return [
            'done'     => false,
            'progress' => $progress,
            'phase'    => $phase,
            'batch'    => $newBatch,
            'sent'     => $itemsSent,
        ];
    }

    // =========================================================================
    // OBTENER ITEMS POR FASE
    // =========================================================================

    private static function getItemsBatch($phase, $offset, $limit)
    {
        switch ($phase) {
            case self::PHASE_CATEGORIES:
                return Db::getInstance()->executeS(
                    'SELECT id_category FROM `' . _DB_PREFIX_ . 'category`
                     WHERE id_category > 2
                     ORDER BY id_parent ASC, id_category ASC
                     LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset
                );

            case self::PHASE_MANUFACTURERS:
                return Db::getInstance()->executeS(
                    'SELECT id_manufacturer FROM `' . _DB_PREFIX_ . 'manufacturer`
                     WHERE active = 1
                     ORDER BY id_manufacturer ASC
                     LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset
                );

            case self::PHASE_ATTRIBUTES:
                return Db::getInstance()->executeS(
                    'SELECT id_attribute_group FROM `' . _DB_PREFIX_ . 'attribute_group`
                     ORDER BY id_attribute_group ASC
                     LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset
                );

            case self::PHASE_FEATURES:
                return Db::getInstance()->executeS(
                    'SELECT id_feature FROM `' . _DB_PREFIX_ . 'feature`
                     ORDER BY id_feature ASC
                     LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset
                );

            case self::PHASE_PRODUCTS:
                return Db::getInstance()->executeS(
                    'SELECT id_product FROM `' . _DB_PREFIX_ . 'product`
                     ORDER BY id_product ASC
                     LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset
                );

            case self::PHASE_IMAGES:
                // Imágenes: la slave las descarga directamente via URL
                // Aquí solo mandamos los metadatos + URL
                return Db::getInstance()->executeS(
                    'SELECT id_image, id_product FROM `' . _DB_PREFIX_ . 'image`
                     ORDER BY id_product ASC, position ASC
                     LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset
                );

            default:
                return [];
        }
    }

    // =========================================================================
    // SERIALIZAR LOTE
    // =========================================================================

    private static function serializeBatch($phase, $items, $job)
    {
        $idLang      = SyncMasterVersionCompat::getDefaultLangId();
        $fieldConfig = SyncMasterFieldConfig::getForConnection((int)$job['id_connection']);
        $serialized  = [];

        foreach ($items as $item) {
            switch ($phase) {
                case self::PHASE_CATEGORIES:
                    $data = SyncMasterSerializer::serializeCategory((int)$item['id_category']);
                    break;

                case self::PHASE_MANUFACTURERS:
                    $mfr  = new Manufacturer((int)$item['id_manufacturer'], $idLang);
                    $data = Validate::isLoadedObject($mfr) ? [
                        'entity'        => 'manufacturer',
                        'master_id'     => (int)$mfr->id,
                        'name'          => $mfr->name,
                        'description'   => $mfr->description,
                        'active'        => (bool)$mfr->active,
                    ] : null;
                    break;

                case self::PHASE_ATTRIBUTES:
                    $group = new AttributeGroup((int)$item['id_attribute_group'], $idLang);
                    $attrs = Db::getInstance()->executeS(
                        'SELECT a.id_attribute, al.name
                         FROM `' . _DB_PREFIX_ . 'attribute` a
                         LEFT JOIN `' . _DB_PREFIX_ . 'attribute_lang` al
                             ON al.id_attribute = a.id_attribute AND al.id_lang = ' . (int)$idLang . '
                         WHERE a.id_attribute_group = ' . (int)$item['id_attribute_group']
                    );
                    $data = [
                        'entity'        => 'attribute_group',
                        'master_id'     => (int)$item['id_attribute_group'],
                        'name'          => $group->name,
                        'is_color'      => (bool)$group->is_color_group,
                        'attributes'    => $attrs,
                    ];
                    break;

                case self::PHASE_FEATURES:
                    $feature = new Feature((int)$item['id_feature'], $idLang);
                    $values  = Db::getInstance()->executeS(
                        'SELECT fv.id_feature_value, fvl.value
                         FROM `' . _DB_PREFIX_ . 'feature_value` fv
                         LEFT JOIN `' . _DB_PREFIX_ . 'feature_value_lang` fvl
                             ON fvl.id_feature_value = fv.id_feature_value
                             AND fvl.id_lang = ' . (int)$idLang . '
                         WHERE fv.id_feature = ' . (int)$item['id_feature'] . '
                         AND fv.custom = 0'
                    );
                    $data = [
                        'entity'    => 'feature',
                        'master_id' => (int)$item['id_feature'],
                        'name'      => $feature->name,
                        'values'    => $values,
                    ];
                    break;

                case self::PHASE_PRODUCTS:
                    $data = SyncMasterSerializer::serializeProduct(
                        (int)$item['id_product'],
                        $fieldConfig
                    );
                    if ($data) {
                        // Aplicar regla de precio
                        $data = SyncMasterPriceRule::apply($data, (int)$job['id_connection']);
                        $data['action'] = 'create';
                    }
                    break;

                case self::PHASE_IMAGES:
                    $baseUrl = SyncMasterVersionCompat::getShopBaseUrl();
                    $idImage = (int)$item['id_image'];
                    $data = [
                        'entity'      => 'image',
                        'id_image'    => $idImage,
                        'id_product'  => (int)$item['id_product'],
                        'url'         => $baseUrl . '/img/p/'
                            . Image::getImgFolderStatic($idImage)
                            . $idImage . '.jpg',
                    ];
                    break;

                default:
                    $data = null;
            }

            if ($data) {
                $serialized[] = $data;
            }
        }

        return $serialized;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private static function getNextPhase($currentPhase)
    {
        $idx = array_search($currentPhase, self::PHASES);
        if ($idx === false || $idx >= count(self::PHASES) - 1) {
            return null;
        }
        return self::PHASES[$idx + 1];
    }

    private static function calcProgress($job, $currentPhase)
    {
        // Pesos de cada fase sobre el total (suma = 100)
        $phaseWeights = [
            self::PHASE_CATEGORIES    => 5,
            self::PHASE_MANUFACTURERS => 2,
            self::PHASE_ATTRIBUTES    => 3,
            self::PHASE_FEATURES      => 3,
            self::PHASE_PRODUCTS      => 50,
            self::PHASE_IMAGES        => 35,
            self::PHASE_VERIFICATION  => 2,
        ];

        $totalWeight   = array_sum($phaseWeights);
        $doneWeight    = 0;
        $currentWeight = 0;

        foreach (self::PHASES as $phase) {
            if ($phase === $currentPhase) {
                // Progreso dentro de la fase actual usando el total de esa fase
                $phaseTotal = self::countPhaseItems($phase);
                $total      = max(1, $phaseTotal);
                $done       = min((int)$job['processed_items'], $total);
                $currentWeight = $phaseWeights[$phase] * ($done / $total);
                break;
            }
            $doneWeight += (isset($phaseWeights[$phase]) ? $phaseWeights[$phase] : 0);
        }

        // Nunca devolver 100 aquí (solo cuando done=true); cap a 99
        return min(99, (int)(($doneWeight + $currentWeight) / $totalWeight * 100));
    }

    private static function countPhaseItems($phase)
    {
        switch ($phase) {
            case self::PHASE_CATEGORIES:
                return self::countCategories();
            case self::PHASE_MANUFACTURERS:
                return self::countManufacturers();
            case self::PHASE_PRODUCTS:
                return self::countProducts();
            case self::PHASE_IMAGES:
                return (int)Db::getInstance()->getValue(
                    'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'image`'
                );
            default:
                return 10; // attributes/features/verification: tamaño estimado pequeño
        }
    }

    private static function countProducts()
    {
        return (int)Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product`'
        );
    }

    private static function countCategories()
    {
        return (int)Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'category` WHERE id_category > 2'
        );
    }

    private static function countManufacturers()
    {
        return (int)Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'manufacturer` WHERE active = 1'
        );
    }

    private static function getConnection($idConnection)
    {
        return Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'sync_connections`
             WHERE id_connection = ' . (int)$idConnection
        );
    }

    // =========================================================================
    // ESTADO DEL JOB (para el panel AJAX)
    // =========================================================================

    public static function getJobStatus($idJob)
    {
        return Db::getInstance()->getRow(
            'SELECT j.*, c.name AS connection_name
             FROM `' . _DB_PREFIX_ . 'sync_initial_job` j
             LEFT JOIN `' . _DB_PREFIX_ . 'sync_connections` c
                 ON c.id_connection = j.id_connection
             WHERE j.id_job = ' . (int)$idJob
        );
    }

    public static function pauseJob($idJob)
    {
        Db::getInstance()->update('sync_initial_job', [
            'status' => 'paused',
        ], 'id_job = ' . (int)$idJob);
    }

    public static function cancelJob($idJob)
    {
        Db::getInstance()->update('sync_initial_job', [
            'status' => 'failed',
            'error_log' => 'Cancelado manualmente',
        ], 'id_job = ' . (int)$idJob);
    }

    /**
     * Lista de jobs para el panel admin
     */
    public static function getJobsForConnection($idConnection)
    {
        return Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'sync_initial_job`
             WHERE id_connection = ' . (int)$idConnection . '
             ORDER BY date_add DESC
             LIMIT 10'
        );
    }
}
