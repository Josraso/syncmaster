<?php
/**
 * SyncMasterSerializer
 * Convierte cualquier entidad de PrestaShop a JSON normalizado v1.0
 * Compatible PS 1.6 → 9
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SyncMasterSerializer
{
    const PAYLOAD_VERSION = '1.0';

    // =========================================================================
    // PRODUCTO COMPLETO
    // =========================================================================

    /**
     * Serializa un producto completo a array normalizado
     *
     * @param  int   $idProduct
     * @param  array $fieldConfig  Lista de campos habilitados (de sync_field_config)
     * @return array|null
     */
    public static function serializeProduct($idProduct, array $fieldConfig = [])
    {
        $idLang    = SyncMasterVersionCompat::getDefaultLangId();
        $languages = SyncMasterVersionCompat::getLanguages();

        $product = new Product((int)$idProduct, true, $idLang);
        if (!Validate::isLoadedObject($product)) {
            return null;
        }

        $data = [
            'sync_version' => self::PAYLOAD_VERSION,
            'entity'       => 'product',
            'master_id'    => (int)$idProduct,
            'timestamp'    => date('c'),
        ];

        // -----------------------------------------------------------------
        // Grupo: datos básicos
        // -----------------------------------------------------------------
        if (self::fieldEnabled('reference', $fieldConfig)) {
            $data['reference'] = $product->reference;
        }
        if (self::fieldEnabled('supplier_reference', $fieldConfig)) {
            $data['supplier_reference'] = $product->supplier_reference;
        }
        if (self::fieldEnabled('ean13', $fieldConfig)) {
            $data['ean13'] = $product->ean13;
        }
        if (self::fieldEnabled('upc', $fieldConfig)) {
            $data['upc'] = $product->upc;
        }
        if (self::fieldEnabled('condition', $fieldConfig)) {
            $data['condition'] = $product->condition;
        }
        if (self::fieldEnabled('active', $fieldConfig)) {
            $data['active'] = (bool)$product->active;
        }
        // product_type: PS 8+ distingue 'standard'/'combinations'/'pack'/'virtual'
        // Lo exportamos siempre que exista la propiedad (en PS 1.6/1.7 será null)
        if (isset($product->product_type) && $product->product_type) {
            $data['product_type'] = $product->product_type;
        }
        if (self::fieldEnabled('visibility', $fieldConfig)) {
            $data['visibility'] = $product->visibility;
        }
        if (self::fieldEnabled('online_only', $fieldConfig)) {
            $data['online_only'] = (bool)$product->online_only;
        }
        if (self::fieldEnabled('available_for_order', $fieldConfig)) {
            $data['available_for_order'] = (bool)$product->available_for_order;
        }
        if (self::fieldEnabled('on_sale', $fieldConfig)) {
            $data['on_sale'] = (bool)$product->on_sale;
        }

        // -----------------------------------------------------------------
        // Grupo: dimensiones y peso
        // -----------------------------------------------------------------
        if (self::fieldEnabled('width', $fieldConfig)) {
            $data['width'] = (float)$product->width;
        }
        if (self::fieldEnabled('height', $fieldConfig)) {
            $data['height'] = (float)$product->height;
        }
        if (self::fieldEnabled('depth', $fieldConfig)) {
            $data['depth'] = (float)$product->depth;
        }
        if (self::fieldEnabled('weight', $fieldConfig)) {
            $data['weight'] = (float)$product->weight;
        }

        // -----------------------------------------------------------------
        // Grupo: precios
        // -----------------------------------------------------------------
        if (self::fieldEnabled('price', $fieldConfig)) {
            // Leer precio base directamente de BD para evitar que PS aplique
            // descuentos en caché (specific prices, reglas de precio, etc.)
            $data['price'] = (float)Db::getInstance()->getValue(
                'SELECT price FROM `' . _DB_PREFIX_ . 'product` WHERE id_product = ' . (int)$idProduct
            );
            // price_tax_incl es informativo; el importer usa 'price' (sin IVA, sin descuentos)
            $data['price_tax_incl'] = (float)Product::getPriceStatic((int)$idProduct, true, null, 6);
        }
        if (self::fieldEnabled('wholesale_price', $fieldConfig)) {
            $data['wholesale_price'] = (float)$product->wholesale_price;
        }
        if (self::fieldEnabled('ecotax', $fieldConfig)) {
            $data['ecotax'] = (float)$product->ecotax;
        }
        if (self::fieldEnabled('unit_price', $fieldConfig)) {
            $data['unit_price']       = (float)$product->unit_price;
            $data['unit_price_ratio'] = (float)$product->unit_price_ratio;
        }
        if (self::fieldEnabled('add_shipping_cost', $fieldConfig)) {
            $data['additional_shipping_cost'] = (float)$product->additional_shipping_cost;
        }
        if (self::fieldEnabled('tax_rate', $fieldConfig)) {
            $data['id_tax_rules_group'] = (int)$product->id_tax_rules_group;
            $data['tax_rate']           = (float)$product->getTaxesRate(
                new Address()
            );
        }

        // -----------------------------------------------------------------
        // Grupo: fabricante y proveedor
        // -----------------------------------------------------------------
        if (self::fieldEnabled('manufacturer', $fieldConfig) && $product->id_manufacturer) {
            $manufacturer = new Manufacturer((int)$product->id_manufacturer, $idLang);
            $data['manufacturer'] = [
                'id'   => (int)$product->id_manufacturer,
                'name' => $manufacturer->name,
            ];
        }
        if (self::fieldEnabled('supplier', $fieldConfig) && $product->id_supplier) {
            $supplier = new Supplier((int)$product->id_supplier, $idLang);
            $data['supplier'] = [
                'id'   => (int)$product->id_supplier,
                'name' => $supplier->name,
            ];
        }

        // -----------------------------------------------------------------
        // Grupo: textos multiidioma
        // -----------------------------------------------------------------
        $translations = [];
        foreach ($languages as $lang) {
            $productLang = new Product((int)$idProduct, false, (int)$lang['id_lang']);
            $trans       = [];

            if (self::fieldEnabled('name', $fieldConfig)) {
                $trans['name'] = $productLang->name;
            }
            if (self::fieldEnabled('description', $fieldConfig)) {
                $trans['description'] = $productLang->description;
            }
            if (self::fieldEnabled('description_short', $fieldConfig)) {
                $trans['description_short'] = $productLang->description_short;
            }
            if (self::fieldEnabled('available_now', $fieldConfig)) {
                $trans['available_now'] = $productLang->available_now;
            }
            if (self::fieldEnabled('available_later', $fieldConfig)) {
                $trans['available_later'] = $productLang->available_later;
            }
            if (self::fieldEnabled('meta_title', $fieldConfig)) {
                $trans['meta_title'] = $productLang->meta_title;
            }
            if (self::fieldEnabled('meta_description', $fieldConfig)) {
                $trans['meta_description'] = $productLang->meta_description;
            }
            if (self::fieldEnabled('meta_keywords', $fieldConfig)) {
                $trans['meta_keywords'] = $productLang->meta_keywords;
            }
            if (self::fieldEnabled('link_rewrite', $fieldConfig)) {
                $trans['link_rewrite'] = $productLang->link_rewrite;
            }
            if (self::fieldEnabled('tags', $fieldConfig)) {
                $trans['tags'] = Tag::getProductTags((int)$idProduct);
            }

            if (!empty($trans)) {
                $translations[$lang['iso_code']] = $trans;
            }
        }
        if (!empty($translations)) {
            $data['translations'] = $translations;
        }

        // -----------------------------------------------------------------
        // Grupo: categorías
        // -----------------------------------------------------------------
        if (self::fieldEnabled('categories', $fieldConfig)) {
            $cats = SyncMasterVersionCompat::getProductCategories($idProduct);
            $data['categories'] = array_map('intval', $cats);
            $data['id_category_default'] = (int)$product->id_category_default;

            // Datos de categorías (nombre para modo free ID)
            $catDetails = [];
            foreach ($data['categories'] as $idCat) {
                $cat = new Category((int)$idCat, $idLang);
                if (Validate::isLoadedObject($cat)) {
                    $catDetails[(int)$idCat] = [
                        'id'        => (int)$idCat,
                        'name'      => $cat->name,
                        'id_parent' => (int)$cat->id_parent,
                    ];
                }
            }
            $data['category_details'] = $catDetails;
        }

        // -----------------------------------------------------------------
        // Grupo: características (features)
        // -----------------------------------------------------------------
        if (self::fieldEnabled('features', $fieldConfig)) {
            $features = SyncMasterVersionCompat::getProductFeatures($idProduct, $idLang);
            $serializedFeatures = [];
            foreach ($features as $feature) {
                $featureLangs = [];
                foreach ($languages as $lang) {
                    $fvLang = Db::getInstance()->getRow(
                        'SELECT fvl.value, fl.name as feature_name
                         FROM `' . _DB_PREFIX_ . 'feature_value_lang` fvl
                         LEFT JOIN `' . _DB_PREFIX_ . 'feature_lang` fl
                             ON fl.id_feature = ' . (int)$feature['id_feature'] . '
                             AND fl.id_lang = ' . (int)$lang['id_lang'] . '
                         WHERE fvl.id_feature_value = ' . (int)$feature['id_feature_value'] . '
                         AND fvl.id_lang = ' . (int)$lang['id_lang']
                    );
                    if ($fvLang) {
                        $featureLangs[$lang['iso_code']] = [
                            'feature_name' => $fvLang['feature_name'],
                            'value'        => $fvLang['value'],
                        ];
                    }
                }
                $serializedFeatures[] = [
                    'id_feature'       => (int)$feature['id_feature'],
                    'id_feature_value' => (int)$feature['id_feature_value'],
                    'langs'            => $featureLangs,
                ];
            }
            $data['features'] = $serializedFeatures;
        }

        // -----------------------------------------------------------------
        // Grupo: atributos/combinaciones
        // -----------------------------------------------------------------
        if (self::fieldEnabled('attributes', $fieldConfig)) {
            $data['combinations'] = self::serializeCombinations($idProduct, $idLang, $languages);
        }

        // -----------------------------------------------------------------
        // Grupo: imágenes
        // -----------------------------------------------------------------
        if (self::fieldEnabled('images', $fieldConfig)) {
            $data['images'] = self::serializeImages($idProduct, $idLang);
        }

        // -----------------------------------------------------------------
        // Grupo: stock
        // -----------------------------------------------------------------
        if (self::fieldEnabled('quantity', $fieldConfig)) {
            $data['stock'] = [
                'quantity'    => SyncMasterVersionCompat::getProductStock($idProduct, 0),
                'out_of_stock' => SyncMasterVersionCompat::getProductOutOfStock($idProduct),
            ];
        }

        // -----------------------------------------------------------------
        // Grupo: tipo de producto (virtual, pack)
        // -----------------------------------------------------------------
        if (self::fieldEnabled('virtual_products', $fieldConfig)) {
            $data['is_virtual'] = (bool)$product->is_virtual;
        }
        if (self::fieldEnabled('product_pack', $fieldConfig)) {
            $data['cache_is_pack'] = (bool)$product->cache_is_pack;
            if ($product->cache_is_pack) {
                $data['pack_items'] = Pack::getItems((int)$idProduct, $idLang);
            }
        }

        // -----------------------------------------------------------------
        // Grupo: productos relacionados (accessories)
        // -----------------------------------------------------------------
        if (self::fieldEnabled('product_accessories', $fieldConfig)) {
            $accessories = $product->getAccessories($idLang);
            $data['accessories'] = array_map(function ($acc) {
                return (int)$acc['id_product'];
            }, $accessories ?: []);
        }

        // -----------------------------------------------------------------
        // Grupo: personalización
        // -----------------------------------------------------------------
        if (self::fieldEnabled('customizable', $fieldConfig)) {
            $data['customizable']        = (int)$product->customizable;
            $data['uploadable_files']    = (int)$product->uploadable_files;
            $data['text_fields']         = (int)$product->text_fields;
        }

        return $data;
    }

    // =========================================================================
    // COMBINACIONES
    // =========================================================================

    private static function serializeCombinations($idProduct, $idLang, $languages)
    {
        $combinations = SyncMasterVersionCompat::getProductCombinations($idProduct, $idLang);
        if (empty($combinations)) {
            return [];
        }

        $result = [];
        $seen   = [];

        foreach ($combinations as $comb) {
            $idComb = (int)$comb['id_product_attribute'];
            if (isset($seen[$idComb])) {
                continue;
            }
            $seen[$idComb] = true;

            $combData = [
                'id_product_attribute' => $idComb,
                'reference'            => isset($comb['reference']) ? $comb['reference'] : '',
                'ean13'                => isset($comb['ean13']) ? $comb['ean13'] : '',
                'upc'                  => isset($comb['upc']) ? $comb['upc'] : '',
                'price'                => (float)(isset($comb['price']) ? $comb['price'] : 0),
                'weight'               => (float)(isset($comb['weight']) ? $comb['weight'] : 0),
                'is_default'           => (bool)(isset($comb['default_on']) ? $comb['default_on'] : false),
                'quantity'             => SyncMasterVersionCompat::getProductStock(
                    $idProduct, $idComb
                ),
                'attributes'           => [],
            ];

            // Obtener todos los atributos de esta combinación
            $attrs = Db::getInstance()->executeS(
                'SELECT a.id_attribute, ag.id_attribute_group,
                        al.name as attr_name, agl.name as group_name,
                        ag.is_color_group, a.color
                 FROM `' . _DB_PREFIX_ . 'product_attribute_combination` pac
                 INNER JOIN `' . _DB_PREFIX_ . 'attribute` a
                     ON a.id_attribute = pac.id_attribute
                 INNER JOIN `' . _DB_PREFIX_ . 'attribute_group` ag
                     ON ag.id_attribute_group = a.id_attribute_group
                 LEFT JOIN `' . _DB_PREFIX_ . 'attribute_lang` al
                     ON al.id_attribute = a.id_attribute
                     AND al.id_lang = ' . (int)$idLang . '
                 LEFT JOIN `' . _DB_PREFIX_ . 'attribute_group_lang` agl
                     ON agl.id_attribute_group = ag.id_attribute_group
                     AND agl.id_lang = ' . (int)$idLang . '
                 WHERE pac.id_product_attribute = ' . (int)$idComb
            );

            foreach ($attrs as $attr) {
                $combData['attributes'][] = [
                    'id_attribute_group' => (int)$attr['id_attribute_group'],
                    'id_attribute'       => (int)$attr['id_attribute'],
                    'group_name'         => $attr['group_name'],
                    'attr_name'          => $attr['attr_name'],
                    'is_color_group'     => (bool)$attr['is_color_group'],
                    'color'              => $attr['color'],
                ];
            }

            // Imágenes de la combinación
            $combImages = Db::getInstance()->executeS(
                'SELECT id_image FROM `' . _DB_PREFIX_ . 'product_attribute_image`
                 WHERE id_product_attribute = ' . (int)$idComb
            );
            $combImages = $combImages ?: [];
            $combData['images'] = array_map(
                array('SyncMasterSerializer', '_toInt'),
                array_column($combImages, 'id_image')
            );

            $result[] = $combData;
        }

        return $result;
    }

    // =========================================================================
    // IMÁGENES
    // =========================================================================

    private static function serializeImages($idProduct, $idLang)
    {
        $images  = SyncMasterVersionCompat::getProductImages($idProduct, $idLang);
        $cover   = SyncMasterVersionCompat::getProductCoverImage($idProduct);
        $result  = [];
        $baseUrl = SyncMasterVersionCompat::getShopBaseUrl();

        foreach ($images as $img) {
            $idImage = (int)$img['id_image'];
            $result[] = [
                'id_image'   => $idImage,
                'position'   => (int)$img['position'],
                'is_cover'   => $cover && (int)$cover['id_image'] === $idImage,
                'legend'     => isset($img['legend']) ? $img['legend'] : '',
                // URL para que la slave descargue directamente
                'url'        => $baseUrl . '/img/p/' . Image::getImgFolderStatic($idImage)
                    . $idImage . '.jpg',
                // URLs de tipos de imagen estándar
                'url_home'   => SyncMasterVersionCompat::getImageUrl(
                    $idProduct, $idImage, 'home_default'
                ),
                'url_large'  => SyncMasterVersionCompat::getImageUrl(
                    $idProduct, $idImage, 'large_default'
                ),
            ];
        }

        return $result;
    }

    // =========================================================================
    // CATEGORÍA
    // =========================================================================

    public static function serializeCategory($idCategory)
    {
        $languages = SyncMasterVersionCompat::getLanguages();
        $idLang    = SyncMasterVersionCompat::getDefaultLangId();

        $category = new Category((int)$idCategory, $idLang);
        if (!Validate::isLoadedObject($category)) {
            return null;
        }

        $data = [
            'sync_version' => self::PAYLOAD_VERSION,
            'entity'       => 'category',
            'master_id'    => (int)$idCategory,
            'timestamp'    => date('c'),
            'id_parent'    => (int)$category->id_parent,
            'active'       => (bool)$category->active,
            'position'     => (int)$category->position,
            'translations' => [],
        ];

        // Datos del padre (para modo free ID)
        if ($category->id_parent > 2) {
            $parentCat = new Category((int)$category->id_parent, $idLang);
            if (Validate::isLoadedObject($parentCat)) {
                $data['parent_name'] = $parentCat->name;
            }
        }

        foreach ($languages as $lang) {
            $catLang = new Category((int)$idCategory, (int)$lang['id_lang']);
            $data['translations'][$lang['iso_code']] = [
                'name'             => $catLang->name,
                'description'      => $catLang->description,
                'link_rewrite'     => $catLang->link_rewrite,
                'meta_title'       => $catLang->meta_title,
                'meta_description' => $catLang->meta_description,
                'meta_keywords'    => $catLang->meta_keywords,
            ];
        }

        // Imagen de categoría
        $imgPath = _PS_CAT_IMG_DIR_ . $idCategory . '.jpg';
        if (file_exists($imgPath)) {
            $baseUrl = SyncMasterVersionCompat::getShopBaseUrl();
            $data['image_url'] = $baseUrl . '/img/c/' . $idCategory . '.jpg';
        }

        return $data;
    }

    // =========================================================================
    // STOCK RÁPIDO (solo cantidad, sin datos completos del producto)
    // =========================================================================

    public static function serializeStock($idProduct, $idProductAttribute, $quantity)
    {
        return [
            'sync_version'          => self::PAYLOAD_VERSION,
            'entity'                => 'stock',
            'master_id'             => (int)$idProduct,
            'id_product_attribute'  => (int)$idProductAttribute,
            'quantity'              => (int)$quantity,
            'timestamp'             => date('c'),
        ];
    }

    // =========================================================================
    // UTILIDADES
    // =========================================================================

    /**
     * Comprueba si un campo está habilitado en la config de sincronización.
     * Si fieldConfig está vacío (sin restricciones), todos los campos van.
     */
    private static function fieldEnabled($fieldName, array $fieldConfig)
    {
        if (empty($fieldConfig)) {
            return true;
        }
        return !empty($fieldConfig[$fieldName]) && (bool)$fieldConfig[$fieldName]['sync_enabled'];
    }

    /**
     * Genera un hash de campo para el tracker if_untouched
     */
    public static function hashField($value)
    {
        return md5(serialize($value));
    }

    /** Helper compatible PHP 5.6 para array_map */
    public static function _toInt($v) { return (int)$v; }
}
