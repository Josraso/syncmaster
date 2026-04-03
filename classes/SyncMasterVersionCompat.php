<?php
/**
 * SyncMasterVersionCompat
 * Capa de abstracción para diferencias entre versiones de PrestaShop 1.6 → 9
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SyncMasterVersionCompat
{
    private static $version = null;

    public static function getVersion()
    {
        if (self::$version === null) {
            self::$version = _PS_VERSION_;
        }
        return self::$version;
    }

    public static function isPS16()
    {
        return version_compare(self::getVersion(), '1.7.0', '<');
    }

    public static function isPS17()
    {
        return version_compare(self::getVersion(), '1.7.0', '>=')
            && version_compare(self::getVersion(), '8.0.0', '<');
    }

    public static function isPS8Plus()
    {
        return version_compare(self::getVersion(), '8.0.0', '>=');
    }

    // =========================================================================
    // PRODUCTO
    // =========================================================================

    /**
     * Obtiene imagen de portada de un producto (la API cambia entre versiones)
     */
    public static function getProductCoverImage($idProduct)
    {
        // En todas las versiones el método existe pero el namespace cambia en PS9
        if (self::isPS8Plus() && class_exists('PrestaShop\PrestaShop\Adapter\Image\ImageRetriever')) {
            // PS 8/9: podemos usar el método estático legacy que sigue existiendo
            return Image::getCover((int)$idProduct);
        }
        return Image::getCover((int)$idProduct);
    }

    /**
     * Obtiene todas las imágenes de un producto
     */
    public static function getProductImages($idProduct, $idLang)
    {
        $product = new Product((int)$idProduct);
        if (!Validate::isLoadedObject($product)) {
            return [];
        }
        return $product->getImages((int)$idLang);
    }

    /**
     * Obtiene combinaciones del producto (PS 1.6 vs 1.7+ tienen rutas distintas)
     */
    public static function getProductCombinations($idProduct, $idLang)
    {
        $product = new Product((int)$idProduct, true, (int)$idLang);
        if (!Validate::isLoadedObject($product)) {
            return [];
        }

        if (self::isPS16()) {
            // PS 1.6: getAttributeCombinations
            return $product->getAttributeCombinations((int)$idLang);
        }

        // PS 1.7+: mismo método pero puede estar en namespace diferente en PS9
        return $product->getAttributeCombinations((int)$idLang);
    }

    /**
     * Obtiene las características del producto
     */
    public static function getProductFeatures($idProduct, $idLang)
    {
        return Product::getFeaturesStatic((int)$idProduct);
    }

    /**
     * Obtiene el stock de un producto/combinación
     */
    public static function getProductStock($idProduct, $idProductAttribute = 0)
    {
        if (self::isPS16()) {
            // PS 1.6: StockAvailable::getQuantityAvailableByProduct
            return (int)StockAvailable::getQuantityAvailableByProduct(
                (int)$idProduct,
                (int)$idProductAttribute
            );
        }

        // PS 1.7+
        return (int)StockAvailable::getQuantityAvailableByProduct(
            (int)$idProduct,
            (int)$idProductAttribute
        );
    }

    /**
     * Establece el stock de un producto/combinación
     */
    public static function setProductStock($idProduct, $idProductAttribute, $quantity)
    {
        StockAvailable::setQuantity(
            (int)$idProduct,
            (int)$idProductAttribute,
            (int)$quantity
        );
    }

    /**
     * Obtiene datos out_of_stock de StockAvailable
     */
    public static function getProductOutOfStock($idProduct)
    {
        return StockAvailable::outOfStock((int)$idProduct);
    }

    // =========================================================================
    // CATEGORÍAS
    // =========================================================================

    /**
     * Obtiene el árbol de categorías completo
     */
    public static function getCategoryTree($idLang, $idShop = null)
    {
        if ($idShop === null) {
            $idShop = Context::getContext()->shop->id;
        }

        if (self::isPS16()) {
            return Category::getSimpleCategories((int)$idLang);
        }

        return Category::getSimpleCategories((int)$idLang);
    }

    /**
     * Obtiene categorías de un producto
     */
    public static function getProductCategories($idProduct)
    {
        return Product::getProductCategories((int)$idProduct);
    }

    // =========================================================================
    // IMÁGENES
    // =========================================================================

    /**
     * Obtiene la ruta de una imagen de producto
     * La estructura de carpetas cambió en PS 1.7
     */
    public static function getImagePath($idImage, $type = null)
    {
        $image = new Image((int)$idImage);
        if (!Validate::isLoadedObject($image)) {
            return null;
        }

        $imagePath = self::getProdImgDir() . $image->getExistingImgPath() . '.jpg';

        return file_exists($imagePath) ? $imagePath : null;
    }

    /**
     * Obtiene URL pública de una imagen
     */
    public static function getImageUrl($idProduct, $idImage, $type = 'home_default')
    {
        $context = Context::getContext();

        if (self::isPS16()) {
            $imageObj = new Image((int)$idImage);
            return $context->link->getImageLink(
                'product',
                (int)$idProduct . '-' . (int)$idImage,
                $type
            );
        }

        return $context->link->getImageLink(
            'product',
            (int)$idProduct . '-' . (int)$idImage,
            $type
        );
    }

    // =========================================================================
    // FABRICANTES Y PROVEEDORES
    // =========================================================================

    public static function getManufacturerByName($name)
    {
        return Manufacturer::getIdByName($name);
    }

    public static function getSupplierByName($name)
    {
        // Buscar proveedor por nombre en BD
        $result = Db::getInstance()->getValue(
            'SELECT id_supplier FROM `' . _DB_PREFIX_ . 'supplier`
             WHERE name = \'' . pSQL($name) . '\''
        );
        return $result ? (int)$result : 0;
    }

    // =========================================================================
    // IMPUESTOS
    // =========================================================================

    /**
     * Obtiene el id_tax_rules_group más cercano a un porcentaje dado.
     * Usa ROUND para evitar errores de precisión float (21.0 vs 21.000001).
     */
    public static function getTaxRuleGroupByRate($rate, $countryIso = null)
    {
        $rate = round((float)$rate, 4);

        // Buscar coincidencia exacta (redondeada)
        $sql = 'SELECT trg.id_tax_rules_group
                FROM `' . _DB_PREFIX_ . 'tax_rules_group` trg
                INNER JOIN `' . _DB_PREFIX_ . 'tax_rule` tr
                    ON tr.id_tax_rules_group = trg.id_tax_rules_group
                INNER JOIN `' . _DB_PREFIX_ . 'tax` t
                    ON t.id_tax = tr.id_tax
                WHERE ROUND(t.rate, 4) = ' . $rate . '
                AND trg.active = 1';

        $result = Db::getInstance()->getValue($sql);
        if ($result) {
            return (int)$result;
        }

        // Sin coincidencia exacta → buscar el más cercano (min diferencia)
        $sql2 = 'SELECT trg.id_tax_rules_group,
                        ABS(ROUND(t.rate, 4) - ' . $rate . ') AS diff
                 FROM `' . _DB_PREFIX_ . 'tax_rules_group` trg
                 INNER JOIN `' . _DB_PREFIX_ . 'tax_rule` tr
                     ON tr.id_tax_rules_group = trg.id_tax_rules_group
                 INNER JOIN `' . _DB_PREFIX_ . 'tax` t
                     ON t.id_tax = tr.id_tax
                 WHERE trg.active = 1
                 ORDER BY diff ASC';

        $nearest = Db::getInstance()->getValue($sql2);
        return $nearest ? (int)$nearest : 1;
    }

    /**
     * Crea las filas en product_attribute_combination (asociación combinación→atributos).
     * Reemplaza $product->addAttributeCombinaison() que no existe en PS 8/9.
     *
     * @param int   $idProductAttribute
     * @param int[] $attributeIds
     */
    public static function addAttributeCombinationsSql($idProductAttribute, array $attributeIds)
    {
        $db = Db::getInstance();
        foreach ($attributeIds as $idAttr) {
            $db->insert(
                'product_attribute_combination',
                [
                    'id_attribute'         => (int)$idAttr,
                    'id_product_attribute' => (int)$idProductAttribute,
                ],
                false,
                false,
                Db::INSERT_IGNORE
            );
        }
    }

    /**
     * Actualiza una combinación existente via SQL directo.
     * Reemplaza $product->updateAttribute() que tiene firmas distintas por versión.
     *
     * @param int   $idProductAttribute
     * @param array $comb  (price, weight, reference, ean13, upc, is_default)
     */
    public static function updateProductAttributeSql($idProductAttribute, array $comb)
    {
        $db     = Db::getInstance();
        $idShop = self::getShopId();
        $paCols = self::getTableColumns(_DB_PREFIX_ . 'product_attribute');
        $shCols = self::getTableColumns(_DB_PREFIX_ . 'product_attribute_shop');

        // Todos los posibles campos — solo se enviarán los que existan en la tabla
        // (PS 1.6 no tiene 'reference', 'isbn', 'mpn', etc.)
        $allData = [
            'price'             => (float)(isset($comb['price'])     ? $comb['price']     : 0),
            'weight'            => (float)(isset($comb['weight'])    ? $comb['weight']    : 0),
            'unit_price_impact' => 0,
            'ecotax'            => 0,
            'reference'         => pSQL(isset($comb['reference']) ? $comb['reference'] : ''),
            'supplier_reference'=> '',
            'ean13'             => pSQL(isset($comb['ean13'])     ? $comb['ean13']     : ''),
            'upc'               => pSQL(isset($comb['upc'])       ? $comb['upc']       : ''),
        ];

        $data     = array_intersect_key($allData, array_flip($paCols));
        $shopData = array_intersect_key($allData, array_flip($shCols));

        $defaultVal = !empty($comb['is_default']) ? '1' : 'NULL';
        $wherePA    = 'id_product_attribute = ' . (int)$idProductAttribute;
        $whereShop  = $wherePA . ' AND id_shop = ' . (int)$idShop;

        if ($data)     { $db->update('product_attribute',      $data,     $wherePA);   }
        if ($shopData) { $db->update('product_attribute_shop', $shopData, $whereShop); }

        // default_on via SQL crudo para escribir NULL sin null_values=true
        $db->execute('UPDATE `' . _DB_PREFIX_ . 'product_attribute`
                      SET `default_on` = ' . $defaultVal . ' WHERE ' . $wherePA);
        $db->execute('UPDATE `' . _DB_PREFIX_ . 'product_attribute_shop`
                      SET `default_on` = ' . $defaultVal . ' WHERE ' . $whereShop);
    }

    // =========================================================================
    // ATRIBUTOS
    // =========================================================================

    /**
     * Busca o crea un grupo de atributos por nombre
     */
    public static function findOrCreateAttributeGroup($name, $idLang)
    {
        // Buscar existente por nombre en cualquier idioma (fallback a todos los idiomas)
        $id = (int)Db::getInstance()->getValue(
            'SELECT agl.id_attribute_group
             FROM `' . _DB_PREFIX_ . 'attribute_group_lang` agl
             WHERE agl.name = \'' . pSQL($name) . '\'
             AND agl.id_lang = ' . (int)$idLang
        );
        if ($id) {
            return $id;
        }
        // Segundo intento: buscar en cualquier idioma
        $id = (int)Db::getInstance()->getValue(
            'SELECT agl.id_attribute_group
             FROM `' . _DB_PREFIX_ . 'attribute_group_lang` agl
             WHERE agl.name = \'' . pSQL($name) . '\''
        );
        if ($id) {
            return $id;
        }

        // Crear con SQL directo (evita excepciones del ORM AttributeGroup::add())
        $db       = Db::getInstance();
        $position = (int)$db->getValue(
            'SELECT COALESCE(MAX(position),0)+1 FROM `' . _DB_PREFIX_ . 'attribute_group`'
        );
        $db->insert('attribute_group', [
            'is_color_group' => 0,
            'group_type'     => 'select',
            'position'       => $position,
        ]);
        $id = (int)$db->Insert_ID();
        if (!$id) {
            return 0;
        }

        foreach (Language::getLanguages(false) as $lang) {
            $db->insert('attribute_group_lang', [
                'id_attribute_group' => $id,
                'id_lang'            => (int)$lang['id_lang'],
                'name'               => pSQL($name),
                'public_name'        => pSQL($name),
            ], false, false, Db::INSERT_IGNORE);
        }

        // attribute_group_shop (PS 1.7+)
        if (self::getTableColumns(_DB_PREFIX_ . 'attribute_group_shop')) {
            $db->insert('attribute_group_shop', [
                'id_attribute_group' => $id,
                'id_shop'            => self::getShopId(),
            ], false, false, Db::INSERT_IGNORE);
        }

        return $id;
    }

    /**
     * Busca o crea un valor de atributo
     */
    public static function findOrCreateAttribute($idAttributeGroup, $valueName, $idLang)
    {
        $sql = 'SELECT al.id_attribute
                FROM `' . _DB_PREFIX_ . 'attribute_lang` al
                INNER JOIN `' . _DB_PREFIX_ . 'attribute` a
                    ON a.id_attribute = al.id_attribute
                WHERE al.name = \'' . pSQL($valueName) . '\'
                AND al.id_lang = ' . (int)$idLang . '
                AND a.id_attribute_group = ' . (int)$idAttributeGroup . '';

        $id = (int)Db::getInstance()->getValue($sql);
        if ($id) {
            return $id;
        }

        // Direct SQL insert — avoids PHP 8 conflict with built-in Attribute class
        // (PHP 8.0 defines a built-in Attribute class with no add() method)
        $position = (int)Db::getInstance()->getValue(
            'SELECT COALESCE(MAX(position), 0) + 1 FROM `' . _DB_PREFIX_ . 'attribute`'
            . ' WHERE id_attribute_group = ' . (int)$idAttributeGroup
        );

        Db::getInstance()->insert('attribute', [
            'id_attribute_group' => (int)$idAttributeGroup,
            'color'              => '',
            'position'           => $position,
        ]);
        $id = (int)Db::getInstance()->Insert_ID();

        if (!$id) {
            return 0;
        }

        foreach (Language::getLanguages(false) as $lang) {
            Db::getInstance()->insert('attribute_lang', [
                'id_attribute' => $id,
                'id_lang'      => (int)$lang['id_lang'],
                'name'         => pSQL($valueName),
            ], false, false, Db::INSERT_IGNORE);
        }

        // attribute_shop (PS 1.7+)
        if (self::getTableColumns(_DB_PREFIX_ . 'attribute_shop')) {
            Db::getInstance()->insert('attribute_shop', [
                'id_attribute' => $id,
                'id_shop'      => self::getShopId(),
            ], false, false, Db::INSERT_IGNORE);
        }

        return $id;
    }

    // =========================================================================
    // CARACTERÍSTICAS
    // =========================================================================

    public static function findOrCreateFeature($name, $idLang)
    {
        $sql = 'SELECT fl.id_feature
                FROM `' . _DB_PREFIX_ . 'feature_lang` fl
                WHERE fl.name = \'' . pSQL($name) . '\'
                AND fl.id_lang = ' . (int)$idLang . '';

        $id = (int)Db::getInstance()->getValue($sql);
        if ($id) {
            return $id;
        }

        $feature = new Feature();
        $feature->position = 0;
        foreach (Language::getLanguages(false) as $lang) {
            $feature->name[$lang['id_lang']] = $name;
        }
        $feature->add();

        return (int)$feature->id;
    }

    public static function findOrCreateFeatureValue($idFeature, $value, $idLang)
    {
        $sql = 'SELECT fvl.id_feature_value
                FROM `' . _DB_PREFIX_ . 'feature_value_lang` fvl
                INNER JOIN `' . _DB_PREFIX_ . 'feature_value` fv
                    ON fv.id_feature_value = fvl.id_feature_value
                WHERE fvl.value = \'' . pSQL($value) . '\'
                AND fvl.id_lang = ' . (int)$idLang . '
                AND fv.id_feature = ' . (int)$idFeature . '';

        $id = (int)Db::getInstance()->getValue($sql);
        if ($id) {
            return $id;
        }

        $fv = new FeatureValue();
        $fv->id_feature  = (int)$idFeature;
        $fv->custom      = 0;
        foreach (Language::getLanguages(false) as $lang) {
            $fv->value[$lang['id_lang']] = $value;
        }
        $fv->add();

        return (int)$fv->id;
    }

    // =========================================================================
    // UTILIDADES DE CONTEXTO
    // =========================================================================

    /**
     * Idioma por defecto de la tienda
     */
    public static function getDefaultLangId()
    {
        return (int)Configuration::get('PS_LANG_DEFAULT');
    }

    /**
     * Tienda activa
     */
    public static function getShopId()
    {
        return (int)Context::getContext()->shop->id;
    }

    /**
     * Lista de idiomas instalados
     */
    public static function getLanguages()
    {
        return Language::getLanguages(false);
    }

    /** Cache de columnas por tabla para no repetir el info_schema */
    private static $tableColumns = [];

    private static function getTableColumns($table)
    {
        if (!isset(self::$tableColumns[$table])) {
            $rows = Db::getInstance()->executeS(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = '" . pSQL($table) . "'"
            );
            self::$tableColumns[$table] = $rows
                ? array_column($rows, 'COLUMN_NAME')
                : [];
        }
        return self::$tableColumns[$table];
    }

    /**
     * Crea una combinación de producto usando SQL directo para evitar diferencias
     * de firma de addProductAttribute() entre PS 1.6 / 1.7 / 8 / 9.
     * Solo incluye columnas que realmente existen en la tabla (detecta PS 8 isbn, mpn, etc.).
     *
     * @param  Product $product
     * @param  array   $comb   (price, weight, reference, ean13, upc, is_default, quantity)
     * @return int     id_product_attribute creado, 0 si error
     */
    public static function addProductAttributeCompat($product, array $comb)
    {
        $db        = Db::getInstance();
        $idProduct = (int)$product->id;
        $price     = (float)(isset($comb['price'])     ? $comb['price']     : 0);
        $weight    = (float)(isset($comb['weight'])    ? $comb['weight']    : 0);
        $reference = pSQL(isset($comb['reference']) ? $comb['reference'] : '');
        $ean13     = pSQL(isset($comb['ean13'])     ? $comb['ean13']     : '');
        $upc       = pSQL(isset($comb['upc'])       ? $comb['upc']       : '');
        // PS usa NULL (no 0) para combinaciones no-default:
        // product_attribute y product_attribute_shop tienen UNIQUE KEY (id_product, default_on)
        // → múltiples NULL están permitidos, múltiples 0 violan la constraint (error 1062)
        // NOTA: NO usamos null_values=true en Db::insert porque en PS eso trata '' igual que null,
        // lo que pondría isbn/mpn/etc. como NULL en lugar de '' (rompe PS8 CombinationDetails::__construct)
        // Solución: excluimos default_on del array para no-default (el campo tiene DEFAULT NULL en schema)
        $isDefault = !empty($comb['is_default']);
        $quantity  = (int)(isset($comb['quantity']) ? $comb['quantity'] : 0);
        $idShop    = self::getShopId();
        $paCols    = self::getTableColumns(_DB_PREFIX_ . 'product_attribute');

        // Todos los valores posibles para las distintas versiones de PS
        $allData = [
            'id_product'          => $idProduct,
            'reference'           => $reference,
            'supplier_reference'  => '',
            'ean13'               => $ean13,
            'isbn'                => '',   // PS 1.7.3+
            'upc'                 => $upc,
            'mpn'                 => '',   // PS 8
            'location'            => '',
            'unit_price_impact'   => 0,
            'ecotax'              => 0,
            'weight'              => $weight,
            'price'               => $price,
            'minimal_quantity'    => 1,
            'low_stock_threshold' => 0,    // PS 1.7.7+
            'low_stock_alert'     => 0,    // PS 1.7.7+
            'available_date'      => '0000-00-00', // PS 1.7+
            'wholesale_price'     => 0,
            'quantity'            => $quantity,  // solo PS 1.6
        ];
        // Solo incluir default_on cuando es 1 — las no-default omiten la clave para que
        // la BD use DEFAULT NULL, evitando el 1062 en UNIQUE KEY (id_product, default_on)
        if ($isDefault) {
            $allData['default_on'] = 1;
        }

        // Solo incluir columnas que existen en esta instalación
        $row = array_intersect_key($allData, array_flip($paCols));

        if (!$db->insert('product_attribute', $row)) {
            return 0;
        }
        $idPA = (int)$db->Insert_ID();
        if (!$idPA) {
            return 0;
        }

        // product_attribute_shop (multi-shop) — mismas columnas del shop
        $shopCols    = self::getTableColumns(_DB_PREFIX_ . 'product_attribute_shop');
        $shopAllData = $allData;
        $shopAllData['id_product_attribute'] = $idPA;
        $shopAllData['id_shop']              = $idShop;
        $shopRow = array_intersect_key($shopAllData, array_flip($shopCols));
        $db->insert('product_attribute_shop', $shopRow, false, false, Db::INSERT_IGNORE);

        return $idPA;
    }

    /**
     * Marca el producto como tipo 'combinations' en PS 8+ (donde existe la columna product_type).
     * En PS 1.6 / 1.7 no hace nada (la columna no existe).
     *
     * @param int $idProduct  ID local del producto en la slave
     */
    public static function setProductTypeCombinations($idProduct)
    {
        $cols = self::getTableColumns(_DB_PREFIX_ . 'product');
        if (!in_array('product_type', $cols)) {
            return; // PS 1.6 / 1.7 — no existe la columna
        }
        Db::getInstance()->update('product', ['product_type' => 'combinations'],
            'id_product = ' . (int)$idProduct
        );
        // También en product_shop si existe
        $shopCols = self::getTableColumns(_DB_PREFIX_ . 'product_shop');
        if (in_array('product_type', $shopCols)) {
            Db::getInstance()->update('product_shop', ['product_type' => 'combinations'],
                'id_product = ' . (int)$idProduct
            );
        }
    }

    /**
     * Devuelve la ruta al directorio de imágenes de producto.
     * _PS_PROD_IMG_DIR_ no está definida en PS 9 fuera del contexto normal.
     *
     * @return string  ruta absoluta con slash al final
     */
    public static function getProdImgDir()
    {
        if (defined('_PS_PROD_IMG_DIR_')) {
            return _PS_PROD_IMG_DIR_;
        }
        // Fallback para PS 8 / 9 donde la constante puede no estar definida
        return _PS_IMG_DIR_ . 'p' . DIRECTORY_SEPARATOR;
    }

    /**
     * Llama a StockAvailable::postProcess() sólo si existe (no en PS 1.7 < 1.7.8 aprox).
     * En versiones sin este método el stock ya queda correcto via setProductStock().
     *
     * @param Product $product
     */
    public static function postProcessCombinations($product)
    {
        // Reparar columnas de texto que pueden haber quedado como NULL por imports anteriores.
        // PS 8 CombinationDetails::__construct() requiere string, no null, en isbn/mpn/etc.
        $paCols = self::getTableColumns(_DB_PREFIX_ . 'product_attribute');
        $fixCols = [];
        foreach (['isbn', 'mpn', 'supplier_reference', 'location', 'ean13', 'upc', 'reference'] as $col) {
            if (in_array($col, $paCols)) {
                $fixCols[] = '`' . $col . '` = COALESCE(`' . $col . '`, \'\')';
            }
        }
        if ($fixCols) {
            Db::getInstance()->execute(
                'UPDATE `' . _DB_PREFIX_ . 'product_attribute` SET ' . implode(', ', $fixCols)
                . ' WHERE id_product = ' . (int)$product->id
            );
        }

        $product->checkDefaultAttributes();
        if (method_exists('StockAvailable', 'postProcess')) {
            StockAvailable::postProcess($product);
        }
    }

    /**
     * Mapa iso_code → id_lang
     */
    public static function getLanguageMap()
    {
        $map = [];
        foreach (self::getLanguages() as $lang) {
            $map[$lang['iso_code']] = (int)$lang['id_lang'];
        }
        return $map;
    }

    /**
     * Idiomas configurados para sync (filtra por SYNCMASTER_SYNC_LANGS si está configurado)
     */
    public static function getSyncLanguages()
    {
        $all = self::getLanguages();
        $configured = Configuration::get('SYNCMASTER_SYNC_LANGS');
        if (!$configured) {
            return $all; // sin config → todos
        }
        $isos = array_map('trim', explode(',', $configured));
        return array_values(array_filter($all, function($lang) use ($isos) {
            return in_array($lang['iso_code'], $isos);
        }));
    }

    /**
     * Mapa iso_code → id_lang solo para idiomas de sync configurados
     */
    public static function getSyncLanguageMap()
    {
        $map = [];
        foreach (self::getSyncLanguages() as $lang) {
            $map[$lang['iso_code']] = (int)$lang['id_lang'];
        }
        return $map;
    }

    /**
     * Encuentra el id_product_attribute de una combinación que tenga EXACTAMENTE
     * los atributos indicados en $attributeIds, para el producto dado.
     * Reemplaza Product::getIdProductAttributesByIdAttributes() que no existe en PS 9.
     *
     * @param  int   $idProduct
     * @param  int[] $attributeIds
     * @return int   0 si no existe
     */
    public static function findCombinationByAttributes($idProduct, array $attributeIds)
    {
        if (empty($attributeIds)) {
            return 0;
        }
        $db   = Db::getInstance();
        $n    = count($attributeIds);
        $list = implode(',', array_map('intval', $attributeIds));

        // Combinaciones del producto que contienen AL MENOS todos los atributos pedidos
        $sql = 'SELECT pac.id_product_attribute
                FROM `' . _DB_PREFIX_ . 'product_attribute_combination` pac
                INNER JOIN `' . _DB_PREFIX_ . 'product_attribute` pa
                    ON pa.id_product_attribute = pac.id_product_attribute
                WHERE pa.id_product = ' . (int)$idProduct . '
                  AND pac.id_attribute IN (' . $list . ')
                GROUP BY pac.id_product_attribute
                HAVING COUNT(*) = ' . (int)$n;

        $candidates = $db->executeS($sql);
        if (!$candidates) {
            return 0;
        }

        // De los candidatos, conservar los que tienen EXACTAMENTE $n atributos (no más)
        foreach ($candidates as $row) {
            $idPa = (int)$row['id_product_attribute'];
            $total = (int)$db->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product_attribute_combination`'
                . ' WHERE id_product_attribute = ' . $idPa
            );
            if ($total === $n) {
                return $idPa;
            }
        }
        return 0;
    }

    /**
     * URL base de la tienda (sin slash final)
     */
    public static function getShopBaseUrl()
    {
        $baseUrl = Tools::getShopDomainSsl(true)
            ?: Tools::getShopDomain(true);
        return rtrim($baseUrl, '/');
    }
}
