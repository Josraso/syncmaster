<?php
/**
 * SyncmasterApiModuleFrontController
 *
 * IMPORTANTE: el nombre de la clase DEBE ser:
 *   {ModuleName en titlecase}{ControllerName en titlecase}ModuleFrontController
 *   → syncmaster + api → SyncmasterApiModuleFrontController
 *
 * URL de acceso:
 *   /index.php?fc=module&module=syncmaster&controller=api&action=X
 *
 * Compatible PS 1.6 → 9
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Cargar clases necesarias (el autoloader puede no haber corrido)
$_smDir = dirname(__FILE__) . '/../../classes/';
foreach ([
    'SyncMasterVersionCompat',
    'SyncMasterHelpers',
    'SyncMasterSerializer',
    'SyncMasterQueue',
    'SyncMasterImporter',
    'SyncMasterInitialJob',
    'SyncMasterApi',
] as $_smClass) {
    if (!class_exists($_smClass)) {
        $f = $_smDir . $_smClass . '.php';
        if (file_exists($f)) require_once $f;
    }
}
unset($_smDir, $_smClass, $f);

class SyncmasterApiModuleFrontController extends ModuleFrontController
{
    /** Forzar HTTPS si está disponible */
    public $ssl = true;

    public function init()
    {
        // En PS 1.7+ indicar que es endpoint AJAX (evita renderizar el layout)
        if (version_compare(_PS_VERSION_, '1.7.0', '>=')) {
            $this->ajax = true;
        }
        parent::init();
    }

    /**
     * PS 1.7+: initContent se llama para generar el output.
     */
    public function initContent()
    {
        $this->handleRequest();
    }

    /**
     * PS 1.6: display() se llama en lugar de initContent().
     */
    public function display()
    {
        $this->handleRequest();
    }

    // =========================================================================
    // ROUTER
    // =========================================================================

    private function handleRequest()
    {
        // Siempre responder en JSON, sin layout
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache');
        header('X-Robots-Tag: noindex');

        try {
            $this->dispatchAction();
        } catch (Exception $e) {
            $this->jsonExit(500, [
                'error' => 'Excepción interna: ' . $e->getMessage(),
                'file'  => basename($e->getFile()) . ':' . $e->getLine(),
            ]);
        } catch (Error $e) {
            // PHP 7+ fatal errors
            $this->jsonExit(500, [
                'error' => 'Error fatal: ' . $e->getMessage(),
                'file'  => basename($e->getFile()) . ':' . $e->getLine(),
            ]);
        }
    }

    private function dispatchAction()
    {
        $action = Tools::getValue('action', '');
        $role   = Configuration::get('SYNCMASTER_ROLE');

        // ping y status funcionan en cualquier rol
        // trigger_resync lo atiende el MASTER (cuando el slave lo solicita)
        // receive, batch y handshake requieren que esta tienda sea slave o both
        if (in_array($action, ['trigger_resync'])) {
            if (!in_array($role, ['master', 'both'])) {
                $this->jsonExit(403, [
                    'error' => 'Esta tienda no está configurada como master'
                        . ' (rol actual: ' . ($role ?: 'no configurado') . ').',
                ]);
            }
        } elseif (!in_array($action, ['ping', 'status'])) {
            if (!in_array($role, ['slave', 'both'])) {
                $this->jsonExit(403, [
                    'error' => 'Esta tienda no está configurada como slave'
                        . ' (rol actual: ' . ($role ?: 'no configurado') . ').',
                ]);
            }
        }

        switch ($action) {
            case 'ping':           $this->handlePing();          break;
            case 'status':         $this->handleStatus();        break;
            case 'handshake':      $this->handleHandshake();     break;
            case 'receive':        $this->handleReceive();        break;
            case 'batch':          $this->handleBatch();          break;
            case 'trigger_resync': $this->handleTriggerResync(); break;
            default:
                $this->jsonExit(400, ['error' => 'Acción desconocida: ' . Tools::safeOutput($action)]);
        }
    }

    // =========================================================================
    // AUTENTICACIÓN HMAC
    // =========================================================================

    /**
     * Valida la firma HMAC de la petición.
     * Devuelve ['body'=>..., 'payload'=>..., 'connection'=>...] o llama a jsonExit().
     */
    private function authenticate()
    {
        // Leer cabeceras (compatibilidad nginx/apache)
        $apiKey = $this->getHeader('X-SyncMaster-Key');
        $sig    = $this->getHeader('X-SyncMaster-Sig');
        $ts     = $this->getHeader('X-SyncMaster-TS');

        if (!$apiKey || !$sig || !$ts) {
            $this->jsonExit(401, ['error' => 'Cabeceras de autenticación ausentes.']);
        }

        // Buscar la conexión por api_key
        $connection = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'sync_connections`
             WHERE api_key = \'' . pSQL($apiKey) . '\' AND active = 1'
        );

        if (!$connection) {
            $this->jsonExit(401, ['error' => 'API key no válida o conexión inactiva.']);
        }

        // Leer body de la petición
        $body = file_get_contents('php://input');
        if ($body === false || $body === '') {
            $this->jsonExit(400, ['error' => 'Body de la petición vacío.']);
        }

        // Verificar firma HMAC-SHA256 + ventana anti-replay
        if (!SyncMasterApi::verifySignature($body, $ts, $sig, $connection['api_secret'])) {
            $this->jsonExit(401, ['error' => 'Firma HMAC no válida o timestamp expirado.']);
        }

        // Decodificar JSON
        $payload = json_decode($body, true);
        if ($payload === null) {
            $this->jsonExit(400, ['error' => 'JSON malformado en el body.']);
        }

        return [
            'body'       => $body,
            'payload'    => $payload,
            'connection' => $connection,
        ];
    }

    // =========================================================================
    // HANDLERS
    // =========================================================================

    private function handlePing()
    {
        // Ping no requiere autenticación — solo confirma que el módulo está activo y accesible.
        $this->jsonExit(200, [
            'status'     => 'ok',
            'ps_version' => _PS_VERSION_,
            'module'     => 'syncmaster',
            'role'       => Configuration::get('SYNCMASTER_ROLE'),
            'ts'         => time(),
        ]);
    }

    /**
     * El slave solicita al master que inicie un job de resync completo.
     * El master valida las credenciales, identifica la conexión y lanza el job.
     */
    private function handleTriggerResync()
    {
        $auth     = $this->authenticate();
        $conn     = $auth['connection'];
        $payload  = $auth['payload'];

        $skipImages   = !empty($payload['skip_images']);
        $idConnection = (int)$conn['id_connection'];

        // Asegurarse de que SyncMasterInitialJob está disponible
        $jobClass = dirname(__FILE__) . '/../../classes/SyncMasterInitialJob.php';
        if (!class_exists('SyncMasterInitialJob') && file_exists($jobClass)) {
            require_once $jobClass;
        }

        $jobId = SyncMasterInitialJob::startOrResume($idConnection, $skipImages);

        $this->jsonExit(200, [
            'success'      => true,
            'job_id'       => $jobId,
            'skip_images'  => $skipImages,
            'id_connection'=> $idConnection,
        ]);
    }

    private function handleStatus()
    {
        $auth = $this->authenticate();

        $this->jsonExit(200, [
            'status'         => 'ok',
            'ps_version'     => _PS_VERSION_,
            'role'           => Configuration::get('SYNCMASTER_ROLE'),
            'sync_version'   => SyncMasterSerializer::PAYLOAD_VERSION,
            'product_count'  => (int)Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product`'
            ),
            'category_count' => (int)Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'category` WHERE id_category > 2'
            ),
            'languages'      => SyncMasterVersionCompat::getLanguages(),
        ]);
    }

    private function handleHandshake()
    {
        $auth       = $this->authenticate();
        $connection = $auth['connection'];

        $this->jsonExit(200, [
            'status'     => 'ready',
            'id_mode'    => $connection['id_mode'],
            'ps_version' => _PS_VERSION_,
            'languages'  => SyncMasterVersionCompat::getLanguages(),
        ]);
    }

    private function handleReceive()
    {
        $auth       = $this->authenticate();
        $connection = $auth['connection'];
        $payload    = $auth['payload'];
        $start      = microtime(true);

        $idConnection = (int)$connection['id_connection'];
        $fieldConfig  = SyncMasterFieldConfig::getForConnection($idConnection);

        // Aplicar regla de precio de la slave (el master aplica la suya propia;
        // la slave puede tener su propio incremento/descuento configurado aquí)
        $payload = SyncMasterPriceRule::apply($payload, $idConnection);

        $importer = new SyncMasterImporter(
            $idConnection,
            $connection['id_mode'],
            $fieldConfig
        );

        try {
            $result = $importer->import($payload);
        } catch (Exception $e) {
            $result = ['success' => false, 'error' => $e->getMessage()];
        }

        $durationMs = (int)((microtime(true) - $start) * 1000);

        SyncMasterLogger::log(
            (int)$connection['id_connection'],
            (isset($payload['entity']) ? $payload['entity'] : 'unknown'),
            (isset($payload['master_id']) ? $payload['master_id'] : null),
            (isset($payload['action']) ? $payload['action'] : 'upsert'),
            $result['success'] ? 'success' : 'error',
            (isset($result['error']) ? $result['error'] : null),
            $durationMs
        );

        if ($result['success']) {
            $this->jsonExit(200, array_merge(['status' => 'ok'], $result));
        } else {
            $this->jsonExit(422, ['error' => $result['error']]);
        }
    }

    private function handleBatch()
    {
        $auth       = $this->authenticate();
        $connection = $auth['connection'];
        $envelope   = $auth['payload'];
        $start      = microtime(true);

        $phase    = (isset($envelope['phase']) ? $envelope['phase'] : '');
        $batchNum = (int)(isset($envelope['batch_num']) ? $envelope['batch_num'] : 0);
        $items    = (isset($envelope['items']) ? $envelope['items'] : []);

        if (empty($items)) {
            $this->jsonExit(200, ['status' => 'ok', 'processed' => 0]);
        }

        $idConnection = (int)$connection['id_connection'];
        $fieldConfig  = SyncMasterFieldConfig::getForConnection($idConnection);

        // Aplicar regla de precio de la slave a cada item del lote
        foreach ($items as &$item) {
            $item = SyncMasterPriceRule::apply($item, $idConnection);
        }
        unset($item);

        $importer = new SyncMasterImporter(
            $idConnection,
            $connection['id_mode'],
            $fieldConfig
        );

        try {
            $results = $importer->importBatch($items, $phase);
        } catch (Exception $e) {
            $results = ['ok' => 0, 'failed' => count($items), 'errors' => [$e->getMessage()]];
        }

        $durationMs = (int)((microtime(true) - $start) * 1000);

        SyncMasterLogger::log(
            (int)$connection['id_connection'],
            'batch_' . $phase,
            null,
            'batch_' . $batchNum,
            $results['failed'] === 0 ? 'success' : ($results['ok'] > 0 ? 'warning' : 'error'),
            'Lote ' . $batchNum . ': OK=' . $results['ok'] . ' FAIL=' . $results['failed']
                . ($results['failed'] > 0 && !empty($results['errors'])
                    ? ' | ' . implode(' / ', array_slice($results['errors'], 0, 3))
                    : ''),
            $durationMs
        );

        $code = ($results['failed'] === 0) ? 200 : 207;
        $this->jsonExit($code, [
            'status'    => $results['failed'] === 0 ? 'ok' : 'partial',
            'phase'     => $phase,
            'batch_num' => $batchNum,
            'ok'        => $results['ok'],
            'failed'    => $results['failed'],
            'errors'    => array_slice((isset($results['errors']) ? $results['errors'] : []), 0, 10),
        ]);
    }

    // =========================================================================
    // UTILIDADES
    // =========================================================================

    /**
     * Lee una cabecera HTTP normalizando para nginx y apache.
     */
    private function getHeader($name)
    {
        // Formato estándar: X-SyncMaster-Key → HTTP_X_SYNCMASTER_KEY
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (!empty($_SERVER[$serverKey])) {
            return $_SERVER[$serverKey];
        }
        // Algunas configuraciones de apache con getallheaders()
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $k => $v) {
                if (strcasecmp($k, $name) === 0) {
                    return $v;
                }
            }
        }
        return '';
    }

    private function jsonExit($code, array $data)
    {
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
