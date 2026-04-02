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

        if (self::isPS16()) {
            // PS 1.6: /img/p/1/2/3/123.jpg (carpetas por dígito)
            $imagePath = _PS_PROD_IMG_DIR_ . $image->getExistingImgPath() . '.jpg';
        } else {
            // PS 1.7+: misma estructura pero puede incluir WebP en PS8+
            $imagePath = _PS_PROD_IMG_DIR_ . $image->getExistingImgPath() . '.jpg';
        }

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

        $attr = new Attribute();
        $attr->id_attribute_group = (int)$idAttributeGroup;
        $attr->position           = 0;
        foreach (Language::getLanguages(false) as $lang) {
            $attr->name[$lang['id_lang']] = $valueName;
        }
        $attr->add();

        return (int)$attr->id;
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
     * URL base de la tienda (sin slash final)
     */
    public static function getShopBaseUrl()
    {
        $baseUrl = Tools::getShopDomainSsl(true)
            ?: Tools::getShopDomain(true);
        return rtrim($baseUrl, '/');
    }
}
