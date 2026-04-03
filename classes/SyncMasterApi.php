<?php
/**
 * SyncMasterApi
 * Cliente HTTP para enviar payloads al slave.
 * cURL puro — sin dependencias externas. Compatible PS 1.6 → 9.
 * Seguridad: API Key + firma HMAC-SHA256 + timestamp anti-replay.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SyncMasterApi
{
    private $remoteUrl;
    private $apiKey;
    private $apiSecret;

    /** Ventana anti-replay en segundos */
    const REPLAY_WINDOW = 300; // 5 minutos

    public function __construct($remoteUrl, $apiKey, $apiSecret)
    {
        $this->remoteUrl = rtrim($remoteUrl, '/');
        $this->apiKey    = $apiKey;
        $this->apiSecret = $apiSecret;
    }

    // =========================================================================
    // ENVÍO DE PAYLOAD (sync tiempo real)
    // =========================================================================

    /**
     * Envía un payload JSON al endpoint slave
     *
     * @param  string $payload  JSON serializado
     * @param  int    $timeout  Timeout en segundos
     * @return array  ['success'=>bool, 'error'=>string|null, 'response'=>array|null]
     */
    public function sendPayload($payload, $timeout = 30)
    {
        $url       = $this->buildEndpointUrl('receive');
        $timestamp = time();
        $signature = $this->sign($payload, $timestamp);

        $headers = [
            'Content-Type: application/json',
            'X-SyncMaster-Key: ' . $this->apiKey,
            'X-SyncMaster-Sig: ' . $signature,
            'X-SyncMaster-TS: '  . $timestamp,
            'X-SyncMaster-Version: ' . SyncMasterSerializer::PAYLOAD_VERSION,
        ];

        return $this->post($url, $payload, $headers, $timeout);
    }

    // =========================================================================
    // SYNC INICIAL — HANDSHAKE
    // =========================================================================

    /**
     * Negocia el inicio de un sync inicial masivo
     * El slave responde con su estado actual (cuántos productos tiene, etc.)
     */
    public function handshake($jobConfig)
    {
        $url       = $this->buildEndpointUrl('handshake');
        $payload   = json_encode($jobConfig);
        $timestamp = time();
        $signature = $this->sign($payload, $timestamp);

        $headers = [
            'Content-Type: application/json',
            'X-SyncMaster-Key: ' . $this->apiKey,
            'X-SyncMaster-Sig: ' . $signature,
            'X-SyncMaster-TS: '  . $timestamp,
        ];

        return $this->post($url, $payload, $headers, 15);
    }

    // =========================================================================
    // SYNC INICIAL — ENVÍO DE LOTE
    // =========================================================================

    /**
     * Envía un lote de entidades (array de payloads)
     *
     * @param  array  $batch    Array de entidades serializadas
     * @param  string $phase    'categories'|'products'|'images'...
     * @param  int    $batchNum Número del lote actual
     * @param  int    $idJob    ID del job en el master
     * @param  int    $timeout
     * @return array
     */
    public function sendBatch($batch, $phase, $batchNum, $idJob, $timeout = 60)
    {
        $url = $this->buildEndpointUrl('batch');

        $envelope = json_encode([
            'phase'      => $phase,
            'batch_num'  => $batchNum,
            'id_job'     => $idJob,
            'count'      => count($batch),
            'items'      => $batch,
            'timestamp'  => date('c'),
        ]);

        $timestamp = time();
        $signature = $this->sign($envelope, $timestamp);

        $headers = [
            'Content-Type: application/json',
            'X-SyncMaster-Key: ' . $this->apiKey,
            'X-SyncMaster-Sig: ' . $signature,
            'X-SyncMaster-TS: '  . $timestamp,
        ];

        return $this->post($url, $envelope, $headers, $timeout);
    }

    // =========================================================================
    // PING / TEST DE CONEXIÓN
    // =========================================================================

    public function ping()
    {
        $url       = $this->buildEndpointUrl('ping');
        $payload   = json_encode(['ts' => time()]);
        $timestamp = time();
        $signature = $this->sign($payload, $timestamp);

        $headers = [
            'Content-Type: application/json',
            'X-SyncMaster-Key: ' . $this->apiKey,
            'X-SyncMaster-Sig: ' . $signature,
            'X-SyncMaster-TS: '  . $timestamp,
        ];

        $start  = microtime(true);
        $result = $this->post($url, $payload, $headers, 10);
        $result['latency_ms'] = (int)((microtime(true) - $start) * 1000);

        return $result;
    }

    // =========================================================================
    // CONSULTAR ESTADO DEL SLAVE
    // =========================================================================

    /**
     * Solicita al master que inicie un job de resync completo hacia esta conexión slave.
     * Se llama desde la tienda slave; el master debe tener rol 'master' o 'both'.
     *
     * @param  bool  $skipImages  Si true el job saltará la fase de imágenes (más rápido)
     * @return array ['success'=>bool, 'job_id'=>int|null, 'error'=>string|null]
     */
    public function requestResync($skipImages = false)
    {
        $url       = $this->buildEndpointUrl('trigger_resync');
        $payload   = json_encode(['ts' => time(), 'skip_images' => (bool)$skipImages]);
        $timestamp = time();
        $signature = $this->sign($payload, $timestamp);

        $headers = [
            'Content-Type: application/json',
            'X-SyncMaster-Key: ' . $this->apiKey,
            'X-SyncMaster-Sig: ' . $signature,
            'X-SyncMaster-TS: '  . $timestamp,
        ];

        return $this->post($url, $payload, $headers, 30);
    }

    /**
     * Pregunta al slave cuántos productos tiene, versión de PS, etc.
     */
    public function getSlaveStatus()
    {
        $url       = $this->buildEndpointUrl('status');
        $payload   = json_encode(['ts' => time()]);
        $timestamp = time();
        $signature = $this->sign($payload, $timestamp);

        $headers = [
            'Content-Type: application/json',
            'X-SyncMaster-Key: ' . $this->apiKey,
            'X-SyncMaster-Sig: ' . $signature,
            'X-SyncMaster-TS: '  . $timestamp,
        ];

        return $this->post($url, $payload, $headers, 15);
    }

    // =========================================================================
    // HTTP CORE
    // =========================================================================

    private function post($url, $body, $headers, $timeout = 30)
    {
        if (!extension_loaded('curl')) {
            return [
                'success'  => false,
                'error'    => 'La extensión cURL no está disponible en este servidor.',
                'response' => null,
            ];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => (int)$timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            // User agent identificativo
            CURLOPT_USERAGENT      => 'SyncMasterPro/' . SyncMasterSerializer::PAYLOAD_VERSION
                . ' PrestaShop/' . _PS_VERSION_,
        ]);

        $responseBody = curl_exec($ch);
        $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return [
                'success'  => false,
                'error'    => 'cURL error: ' . $curlError,
                'response' => null,
                'http_code' => 0,
            ];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $decoded = json_decode($responseBody, true);
            $errorMsg = isset($decoded['error'])
                ? $decoded['error']
                : 'HTTP ' . $httpCode . ': ' . substr($responseBody, 0, 200);

            return [
                'success'   => false,
                'error'     => $errorMsg,
                'response'  => $decoded,
                'http_code' => $httpCode,
            ];
        }

        $decoded = json_decode($responseBody, true);

        return [
            'success'   => true,
            'error'     => null,
            'response'  => $decoded,
            'http_code' => $httpCode,
        ];
    }

    // =========================================================================
    // SEGURIDAD
    // =========================================================================

    /**
     * Firma HMAC-SHA256 del payload + timestamp
     */
    private function sign($payload, $timestamp)
    {
        return hash_hmac('sha256', $payload . '|' . $timestamp, $this->apiSecret);
    }

    /**
     * Verifica una firma recibida (usado en el slave)
     * Método estático para usarlo en el FrontController sin instanciar
     */
    public static function verifySignature($payload, $timestamp, $signature, $secret)
    {
        // Verificar ventana anti-replay
        if (abs(time() - (int)$timestamp) > self::REPLAY_WINDOW) {
            return false;
        }

        $expected = hash_hmac('sha256', $payload . '|' . $timestamp, $secret);
        return hash_equals($expected, $signature);
    }

    // =========================================================================
    // GENERACIÓN DE CLAVES
    // =========================================================================

    /**
     * Genera un par API Key / Secret aleatorio
     */
    public static function generateCredentials()
    {
        return [
            'api_key'    => bin2hex(random_bytes(16)), // 32 chars hex
            'api_secret' => bin2hex(random_bytes(32)), // 64 chars hex
        ];
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function buildEndpointUrl($action)
    {
        // El FrontController del módulo responde en:
        // https://slave.com/index.php?fc=module&module=syncmaster&controller=api&action=X
        return $this->remoteUrl
            . '/index.php?fc=module&module=syncmaster&controller=api&action='
            . urlencode($action);
    }
}
