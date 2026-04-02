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
     * Obtiene el id_tax_rules_group por nombre o tipo aproximado
     */
    public static function getTaxRuleGroupByRate($rate, $countryIso = null)
    {
        // Buscar un tax rule group que se aproxime al rate recibido
        $sql = 'SELECT trg.id_tax_rules_group
                FROM `' . _DB_PREFIX_ . 'tax_rules_group` trg
                INNER JOIN `' . _DB_PREFIX_ . 'tax_rule` tr
                    ON tr.id_tax_rules_group = trg.id_tax_rules_group
                INNER JOIN `' . _DB_PREFIX_ . 'tax` t
                    ON t.id_tax = tr.id_tax
                WHERE t.rate = ' . (float)$rate . '';

        $result = Db::getInstance()->getValue($sql);
        return $result ? (int)$result : 1; // 1 = sin impuesto como fallback
    }

    // =========================================================================
    // ATRIBUTOS
    // =========================================================================

    /**
     * Busca o crea un grupo de atributos por nombre
     */
    public static function findOrCreateAttributeGroup($name, $idLang)
    {
        // Buscar existente
        $sql = 'SELECT agl.id_attribute_group
                FROM `' . _DB_PREFIX_ . 'attribute_group_lang` agl
                WHERE agl.name = \'' . pSQL($name) . '\'
                AND agl.id_lang = ' . (int)$idLang . '';

        $id = (int)Db::getInstance()->getValue($sql);
        if ($id) {
            return $id;
        }

        // Crear nuevo
        $group = new AttributeGroup();
        $group->is_color_group = 0;
        $group->group_type     = 'select';
        $group->position       = 0;
        foreach (Language::getLanguages(false) as $lang) {
            $group->name[$lang['id_lang']] = $name;
            $group->public_name[$lang['id_lang']] = $name;
        }
        $group->add();

        return (int)$group->id;
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

    /**
     * Crea una combinación de producto usando SQL directo para evitar diferencias
     * de firma de addProductAttribute() entre PS 1.6 / 1.7 / 8 / 9.
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
        $reference = isset($comb['reference']) ? pSQL($comb['reference']) : '';
        $ean13     = isset($comb['ean13'])     ? pSQL($comb['ean13'])     : '';
        $upc       = isset($comb['upc'])       ? pSQL($comb['upc'])       : '';
        $default   = !empty($comb['is_default']) ? 1 : 0;
        $quantity  = (int)(isset($comb['quantity']) ? $comb['quantity'] : 0);
        $idShop    = self::getShopId();
        $prefix    = _DB_PREFIX_;

        // Detectar si la tabla tiene columna 'quantity' (PS 1.6) o no (PS 1.7+)
        $hasQtyCol = (bool)$db->getValue(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = '{$prefix}product_attribute'
               AND COLUMN_NAME  = 'quantity'"
        );

        $row = [
            'id_product'         => $idProduct,
            'reference'          => $reference,
            'ean13'              => $ean13,
            'upc'                => $upc,
            'location'           => '',
            'unit_price_impact'  => 0,
            'ecotax'             => 0,
            'weight'             => $weight,
            'default_on'         => $default,
            'price'              => $price,
            'minimal_quantity'   => 1,
        ];
        if ($hasQtyCol) {
            $row['quantity'] = $quantity;
        }

        if (!$db->insert('product_attribute', $row)) {
            return 0;
        }
        $idPA = (int)$db->Insert_ID();

        // product_attribute_shop (multi-shop)
        $shopRow           = $row;
        $shopRow['id_product_attribute'] = $idPA;
        $shopRow['id_shop'] = $idShop;
        $db->insert('product_attribute_shop', $shopRow, false, false, Db::INSERT_IGNORE);

        return $idPA;
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
