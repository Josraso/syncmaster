<?php
/**
 * SyncMasterFieldConfig
 * Gestión de la configuración de campos por conexión
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SyncMasterFieldConfig
{
    /** Definición de todos los campos sincronizables agrupados */
    public static function getAllFields()
    {
        return [
            'basic' => [
                'label'  => 'Datos básicos',
                'fields' => [
                    'name', 'reference', 'supplier_reference', 'ean13', 'upc',
                    'condition', 'active', 'visibility', 'online_only',
                    'available_for_order', 'on_sale', 'manufacturer', 'supplier',
                ],
            ],
            'texts' => [
                'label'  => 'Textos y SEO',
                'fields' => [
                    'description', 'description_short', 'available_now', 'available_later',
                    'tags', 'link_rewrite', 'meta_title', 'meta_description', 'meta_keywords',
                ],
            ],
            'prices' => [
                'label'  => 'Precios',
                'fields' => [
                    'price', 'wholesale_price', 'ecotax', 'unit_price',
                    'add_shipping_cost', 'tax_rate', 'discounts',
                ],
            ],
            'dimensions' => [
                'label'  => 'Dimensiones y peso',
                'fields' => ['width', 'height', 'depth', 'weight'],
            ],
            'stock' => [
                'label'  => 'Stock',
                'fields' => ['quantity', 'out_of_stock'],
            ],
            'classification' => [
                'label'  => 'Clasificación',
                'fields' => ['categories', 'product_category', 'features'],
            ],
            'media' => [
                'label'  => 'Imágenes y adjuntos',
                'fields' => ['images', 'attachments'],
            ],
            'relations' => [
                'label'  => 'Relaciones',
                'fields' => ['attributes', 'product_accessories', 'product_pack', 'virtual_products'],
            ],
            'customization' => [
                'label'  => 'Personalización',
                'fields' => ['customizable'],
            ],
        ];
    }

    /**
     * Obtiene la config de campos para una conexión, indexada por field_name
     */
    public static function getForConnection($idConnection)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'sync_field_config`
             WHERE id_connection = ' . (int)$idConnection
        );

        $config = [];
        foreach ($rows as $row) {
            $config[$row['field_name']] = $row;
        }
        return $config;
    }

    /**
     * Guarda (upsert) la configuración de un campo
     */
    public static function saveField($idConnection, $fieldGroup, $fieldName, $data)
    {
        // Comprobar si existe
        $existing = Db::getInstance()->getValue(
            'SELECT id_config FROM `' . _DB_PREFIX_ . 'sync_field_config`
             WHERE id_connection = ' . (int)$idConnection . '
             AND field_name = \'' . pSQL($fieldName) . '\''
        );

        $row = [
            'id_connection'    => (int)$idConnection,
            'field_group'      => pSQL($fieldGroup),
            'field_name'       => pSQL($fieldName),
            'sync_enabled'     => isset($data['sync_enabled'])     ? (int)$data['sync_enabled']     : 1,
            'overwrite_policy' => isset($data['overwrite_policy'])
                ? pSQL($data['overwrite_policy']) : 'always',
            'price_rule'       => isset($data['price_rule'])
                ? pSQL($data['price_rule'])       : 'none',
            'price_value'      => isset($data['price_value'])
                ? (float)$data['price_value']     : 0.0,
        ];

        if ($existing) {
            return Db::getInstance()->update(
                'sync_field_config',
                $row,
                'id_config = ' . (int)$existing
            );
        }
        return Db::getInstance()->insert('sync_field_config', $row);
    }

    /**
     * Inicializa la config por defecto para una conexión nueva
     * Por defecto: todos los campos habilitados, política ALWAYS
     */
    public static function initDefaults($idConnection)
    {
        $allFields = self::getAllFields();
        foreach ($allFields as $group => $groupData) {
            foreach ($groupData['fields'] as $field) {
                self::saveField($idConnection, $group, $field, [
                    'sync_enabled'     => 1,
                    'overwrite_policy' => 'always',
                    'price_rule'       => 'none',
                    'price_value'      => 0.0,
                ]);
            }
        }
    }
}


// =============================================================================

/**
 * SyncMasterPriceRule
 * Aplica las reglas de precio configuradas por conexión
 */
class SyncMasterPriceRule
{
    /**
     * Aplica la regla de precio del campo 'price' a un payload de producto
     */
    public static function apply(array $payload, $idConnection)
    {
        $config = SyncMasterFieldConfig::getForConnection($idConnection);

        $priceFields = ['price', 'wholesale_price'];

        foreach ($priceFields as $field) {
            if (!isset($payload[$field]) || !isset($config[$field])) {
                continue;
            }

            $rule  = isset($config[$field]['price_rule']) ? $config[$field]['price_rule'] : 'none';
            $value = (float)(isset($config[$field]['price_value']) ? $config[$field]['price_value'] : 0);

            $payload[$field] = self::applyRule($payload[$field], $rule, $value);
        }

        // Aplicar también en combinaciones si existen
        if (!empty($payload['combinations'])) {
            foreach ($payload['combinations'] as &$comb) {
                if (isset($comb['price']) && isset($config['price'])) {
                    $rule  = isset($config['price']['price_rule']) ? $config['price']['price_rule'] : 'none';
                    $value = (float)(isset($config['price']['price_value']) ? $config['price']['price_value'] : 0);
                    $comb['price'] = self::applyRule($comb['price'], $rule, $value);
                }
            }
            unset($comb);
        }

        return $payload;
    }

    private static function applyRule($price, $rule, $value)
    {
        $price = (float)$price;

        switch ($rule) {
            case 'percent_inc':
                return round($price * (1 + $value / 100), 6);
            case 'percent_dec':
                return round($price * (1 - $value / 100), 6);
            case 'fixed_inc':
                return round($price + $value, 6);
            case 'fixed_dec':
                return round(max(0, $price - $value), 6);
            case 'none':
            default:
                return $price;
        }
    }

    public static function getRuleLabel($rule)
    {
        $labels = [
            'none'        => 'Precio original',
            'percent_inc' => 'Incremento porcentual (+%)',
            'percent_dec' => 'Descuento porcentual (−%)',
            'fixed_inc'   => 'Incremento fijo (+€)',
            'fixed_dec'   => 'Descuento fijo (−€)',
        ];
        return isset($labels[$rule]) ? $labels[$rule] : $rule;
    }
}


// =============================================================================

/**
 * SyncMasterLogger
 * Registro de operaciones de sync en sync_log
 */
class SyncMasterLogger
{
    public static function log(
        $idConnection,
        $entityType,
        $entityId,
        $action,
        $status,
        $message = null,
        $durationMs = null
    ) {
        Db::getInstance()->insert('sync_log', [
            'id_connection' => (int)$idConnection,
            'entity_type'   => pSQL($entityType),
            'entity_id'     => $entityId ? (int)$entityId : null,
            'action'        => pSQL($action),
            'status'        => pSQL($status),
            'message'       => $message ? pSQL(substr($message, 0, 1000)) : null,
            'duration_ms'   => $durationMs ? (int)$durationMs : null,
            'date_add'      => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Últimas N entradas del log para una conexión (o todas)
     */
    public static function getRecent($limit = 50, $idConnection = null, $status = null)
    {
        $where = '1=1';
        if ($idConnection) {
            $where .= ' AND id_connection = ' . (int)$idConnection;
        }
        if ($status) {
            $where .= ' AND status = \'' . pSQL($status) . '\'';
        }

        return Db::getInstance()->executeS(
            'SELECT l.*, c.name AS connection_name
             FROM `' . _DB_PREFIX_ . 'sync_log` l
             LEFT JOIN `' . _DB_PREFIX_ . 'sync_connections` c
                 ON c.id_connection = l.id_connection
             WHERE ' . $where . '
             ORDER BY l.date_add DESC
             LIMIT ' . (int)$limit
        );
    }

    /**
     * Elimina logs más antiguos de X días
     */
    public static function cleanup($daysOld = 30)
    {
        Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'sync_log`
             WHERE date_add < DATE_SUB(NOW(), INTERVAL ' . (int)$daysOld . ' DAY)'
        );
    }

    /**
     * Resumen de errores por conexión (para el dashboard)
     */
    public static function getErrorSummary()
    {
        return Db::getInstance()->executeS(
            'SELECT c.name, c.id_connection,
                    COUNT(*) AS error_count,
                    MAX(l.date_add) AS last_error_at
             FROM `' . _DB_PREFIX_ . 'sync_log` l
             INNER JOIN `' . _DB_PREFIX_ . 'sync_connections` c
                 ON c.id_connection = l.id_connection
             WHERE l.status = \'error\'
             AND l.date_add >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY c.id_connection
             ORDER BY error_count DESC'
        );
    }
}
