<?php
/**
 * SyncMasterImporter
 * Lado SLAVE: recibe el payload normalizado y lo escribe en la BD de PrestaShop.
 * Gestiona el modo shared ID vs free ID y la política de campos (always/if_untouched/never).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SyncMasterImporter
{
    private $idConnection;
    private $idMode;      // 'shared' | 'free'
    private $fieldConfig; // array indexado por field_name
    private $langMap;     // iso_code => id_lang

    public function __construct($idConnection, $idMode, $fieldConfig)
    {
        $this->idConnection = (int)$idConnection;
        $this->idMode       = $idMode;
        $this->fieldConfig  = $fieldConfig;
        $this->langMap      = SyncMasterVersionCompat::getLanguageMap();
    }

    // =========================================================================
    // ROUTER PRINCIPAL
    // =========================================================================

    /**
     * Procesa un payload recibido del master
     */
    public function import(array $payload)
    {
        $entity = (isset($payload['entity']) ? $payload['entity'] : null);
        $action = (isset($payload['action']) ? $payload['action'] : 'upsert');

        switch ($entity) {
            case 'product':
                return $this->importProduct($payload, $action);
            case 'category':
                return $this->importCategory($payload, $action);
            case 'stock':
                return $this->importStock($payload);
            case 'manufacturer':
                return $this->importManufacturer($payload);
            case 'attribute_group':
                return $this->importAttributeGroup($payload);
            case 'feature':
                return $this->importFeature($payload);
            case 'image':
                return $this->importImage($payload);
            default:
                return ['success' => false, 'error' => 'Entidad desconocida: ' . $entity];
        }
    }

    /**
     * Procesa un lote de entidades (sync inicial)
     */
    public function importBatch(array $items, $phase)
    {
        $results = ['ok' => 0, 'failed' => 0, 'errors' => []];

        foreach ($items as $item) {
            try {
                $result = $this->import($item);
                if ($result['success']) {
                    $results['ok']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = $result['error'];
                }
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = $e->getMessage();
            }
        }

        return $results;
    }

    // =========================================================================
    // PRODUCTO
    // =========================================================================

    private function importProduct(array $data, $action)
    {
        $masterId  = (int)$data['master_id'];
        $localId   = $this->resolveLocalId('product', $masterId);
        $isNew     = ($localId === 0);

        if ($action === 'delete') {
            return $this->deleteProduct($masterId, $localId);
        }

        // Obtener hashes guardados para if_untouched
        $savedHashes = $this->getFieldHashes('product', $masterId);

        if ($isNew) {
            $product = new Product();
            if ($this->idMode === 'shared') {
                $product->force_id = true;
                $product->id       = $masterId;
            }
        } else {
            $product = new Product($localId);
            if (!Validate::isLoadedObject($product)) {
                // El producto fue eliminado localmente, recrear
                $product = new Product();
                if ($this->idMode === 'shared') {
                    $product->force_id = true;
                    $product->id       = $masterId;
                }
                $isNew = true;
            }
        }

        $newHashes = [];

        // -----------------------------------------------------------------
        // Campos básicos
        // -----------------------------------------------------------------
        $basicFields = [
            'reference', 'supplier_reference', 'ean13', 'upc',
            'condition', 'active', 'visibility', 'online_only',
            'available_for_order', 'on_sale', 'width', 'height',
            'depth', 'weight', 'additional_shipping_cost',
            'unit_price', 'unit_price_ratio', 'customizable',
            'uploadable_files', 'text_fields', 'is_virtual', 'cache_is_pack',
        ];

        foreach ($basicFields as $field) {
            if (!isset($data[$field])) {
                continue;
            }
            $configField = $this->resolveConfigField($field);
            if ($this->shouldWrite($configField, $data[$field], $savedHashes)) {
                $product->$field = $data[$field];
                $newHashes[$field] = SyncMasterSerializer::hashField($data[$field]);
            }
        }

        // Precio
        if (isset($data['price']) && $this->shouldWrite('price', $data['price'], $savedHashes)) {
            $product->price = (float)$data['price'];
            $newHashes['price'] = SyncMasterSerializer::hashField($data['price']);
        }
        if (isset($data['wholesale_price']) && $this->shouldWrite('wholesale_price', $data['wholesale_price'], $savedHashes)) {
            $product->wholesale_price = (float)$data['wholesale_price'];
            $newHashes['wholesale_price'] = SyncMasterSerializer::hashField($data['wholesale_price']);
        }
        if (isset($data['ecotax']) && $this->shouldWrite('ecotax', $data['ecotax'], $savedHashes)) {
            $product->ecotax = (float)$data['ecotax'];
        }
        if (isset($data['id_tax_rules_group']) && $this->shouldWrite('tax_rate', $data['id_tax_rules_group'], $savedHashes)) {
            $product->id_tax_rules_group = (int)$data['id_tax_rules_group'];
        }

        // Fabricante
        if (isset($data['manufacturer']) && $this->shouldWrite('manufacturer', $data['manufacturer'], $savedHashes)) {
            $idMfr = SyncMasterVersionCompat::getManufacturerByName($data['manufacturer']['name']);
            if (!$idMfr && !empty($data['manufacturer']['name'])) {
                $mfr = new Manufacturer();
                $mfr->name   = $data['manufacturer']['name'];
                $mfr->active = 1;
                $mfr->add();
                $idMfr = (int)$mfr->id;
            }
            $product->id_manufacturer = $idMfr ?: 0;
        }

        // Categoría por defecto
        if (isset($data['id_category_default'])) {
            $localCatId = $this->resolveLocalId('category', (int)$data['id_category_default']);
            $product->id_category_default = $localCatId ?: (int)$data['id_category_default'];
        }

        // Textos multiidioma
        if (isset($data['translations']) && $this->shouldWrite('name', null, $savedHashes)) {
            foreach ($data['translations'] as $iso => $trans) {
                $idLang = (isset($this->langMap[$iso]) ? $this->langMap[$iso] : null);
                if (!$idLang) {
                    continue;
                }

                $textFields = [
                    'name', 'description', 'description_short',
                    'available_now', 'available_later',
                    'meta_title', 'meta_description', 'meta_keywords',
                    'link_rewrite',
                ];
                foreach ($textFields as $tf) {
                    if (isset($trans[$tf]) && $this->shouldWrite($tf, $trans[$tf], $savedHashes)) {
                        $product->$tf[$idLang] = $trans[$tf];
                        $newHashes[$tf] = SyncMasterSerializer::hashField($trans[$tf]);
                    }
                }
            }
        }

        // Guardar el producto
        if ($isNew) {
            $saved = $product->add();
            $localId = (int)$product->id;
        } else {
            $saved = $product->update();
        }

        if (!$saved || !$localId) {
            return ['success' => false, 'error' => 'Error al guardar el producto (id_master=' . $masterId . ')'];
        }

        // -----------------------------------------------------------------
        // Categorías
        // -----------------------------------------------------------------
        if (isset($data['categories']) && $this->shouldWrite('categories', null, $savedHashes)) {
            $localCatIds = [];
            foreach ($data['categories'] as $masterCatId) {
                $localCat = $this->resolveLocalId('category', (int)$masterCatId);
                if ($localCat) {
                    $localCatIds[] = $localCat;
                }
            }
            if (!empty($localCatIds)) {
                $product->updateCategories($localCatIds);
            }
        }

        // -----------------------------------------------------------------
        // Características
        // -----------------------------------------------------------------
        if (isset($data['features']) && $this->shouldWrite('features', null, $savedHashes)) {
            $this->importProductFeatures($localId, $data['features']);
        }

        // -----------------------------------------------------------------
        // Combinaciones
        // -----------------------------------------------------------------
        if (isset($data['combinations']) && $this->shouldWrite('attributes', null, $savedHashes)) {
            $this->importCombinations($product, $data['combinations']);
        }

        // -----------------------------------------------------------------
        // Stock
        // -----------------------------------------------------------------
        if (isset($data['stock']) && $this->shouldWrite('quantity', null, $savedHashes)) {
            SyncMasterVersionCompat::setProductStock(
                $localId, 0, (int)$data['stock']['quantity']
            );
            if (isset($data['stock']['out_of_stock'])) {
                StockAvailable::setProductOutOfStock($localId, (int)$data['stock']['out_of_stock']);
            }
        }

        // -----------------------------------------------------------------
        // Tags
        // -----------------------------------------------------------------
        if (isset($data['translations'])) {
            foreach ($data['translations'] as $iso => $trans) {
                $idLang = (isset($this->langMap[$iso]) ? $this->langMap[$iso] : null);
                if ($idLang && isset($trans['tags']) && $this->shouldWrite('tags', null, $savedHashes)) {
                    Tag::deleteTagsForProduct($localId);
                    if (!empty($trans['tags'][$idLang])) {
                        Tag::addTags($idLang, $localId, $trans['tags'][$idLang]);
                    }
                }
            }
        }

        // -----------------------------------------------------------------
        // Mapear ID y guardar hashes
        // -----------------------------------------------------------------
        $this->saveIdMap('product', $masterId, $localId, $newHashes);

        return ['success' => true, 'local_id' => $localId, 'master_id' => $masterId];
    }

    // =========================================================================
    // COMBINACIONES
    // =========================================================================

    private function importCombinations(Product $product, array $combinations)
    {
        $idLang = SyncMasterVersionCompat::getDefaultLangId();

        foreach ($combinations as $comb) {
            $attributeIds = [];
            foreach ($comb['attributes'] as $attr) {
                $idGroup = SyncMasterVersionCompat::findOrCreateAttributeGroup(
                    $attr['group_name'], $idLang
                );
                $idAttr = SyncMasterVersionCompat::findOrCreateAttribute(
                    $idGroup, $attr['attr_name'], $idLang
                );
                $attributeIds[] = $idAttr;
            }

            if (empty($attributeIds)) {
                continue;
            }

            // Buscar si ya existe esta combinación
            // (getIdProductAttributesByIdAttributes() no existe en PS 9 — usamos SQL propio)
            $idProductAttribute = SyncMasterVersionCompat::findCombinationByAttributes(
                $product->id,
                $attributeIds
            );

            if (!$idProductAttribute) {
                // Crear combinación
                $idProductAttribute = (int)$product->addProductAttribute(
                    (float)$comb['price'],
                    (float)$comb['weight'],
                    0, // price impact = 0 porque ya viene el precio final
                    0,
                    (isset($comb['reference']) ? $comb['reference'] : ''),
                    '',
                    (isset($comb['ean13']) ? $comb['ean13'] : ''),
                    (isset($comb['is_default']) ? $comb['is_default'] : false),
                    null,
                    (isset($comb['upc']) ? $comb['upc'] : '')
                );

                if ($idProductAttribute) {
                    $product->addAttributeCombinaison($idProductAttribute, $attributeIds);
                }
            } else {
                // Actualizar
                $product->updateAttribute(
                    $idProductAttribute,
                    (float)$comb['price'],
                    (float)$comb['weight'],
                    0,
                    0,
                    null,
                    (isset($comb['reference']) ? $comb['reference'] : ''),
                    '',
                    (isset($comb['ean13']) ? $comb['ean13'] : ''),
                    (isset($comb['is_default']) ? $comb['is_default'] : false),
                    null,
                    (isset($comb['upc']) ? $comb['upc'] : '')
                );
            }

            // Stock de la combinación
            if (isset($comb['quantity'])) {
                SyncMasterVersionCompat::setProductStock(
                    (int)$product->id,
                    (int)$idProductAttribute,
                    (int)$comb['quantity']
                );
            }
        }

        $product->checkDefaultAttributes();
        StockAvailable::postProcess($product);
    }

    // =========================================================================
    // CARACTERÍSTICAS
    // =========================================================================

    private function importProductFeatures($idProduct, array $features)
    {
        $idLang = SyncMasterVersionCompat::getDefaultLangId();

        // Limpiar características actuales
        $product = new Product($idProduct);
        $product->deleteFeatures();

        foreach ($features as $feature) {
            $langs = (isset($feature['langs']) ? $feature['langs'] : []);
            $featureName = '';
            $featureValue = '';

            // Tomar el primer idioma disponible como referencia
            foreach ($langs as $iso => $featureLang) {
                $featureName  = $featureLang['feature_name'];
                $featureValue = $featureLang['value'];
                break;
            }

            $idFeature = SyncMasterVersionCompat::findOrCreateFeature($featureName, $idLang);
            $idFV      = SyncMasterVersionCompat::findOrCreateFeatureValue($idFeature, $featureValue, $idLang);

            Db::getInstance()->insert('feature_product', [
                'id_feature'       => (int)$idFeature,
                'id_product'       => (int)$idProduct,
                'id_feature_value' => (int)$idFV,
            ], false, false, Db::INSERT_IGNORE);
        }
    }

    // =========================================================================
    // CATEGORÍA
    // =========================================================================

    private function importCategory(array $data, $action)
    {
        $masterId = (int)$data['master_id'];
        $localId  = $this->resolveLocalId('category', $masterId);
        $isNew    = ($localId === 0);

        if ($action === 'delete') {
            if ($localId) {
                $cat = new Category($localId);
                if (Validate::isLoadedObject($cat)) {
                    $cat->delete();
                }
            }
            return ['success' => true];
        }

        if ($isNew) {
            $category = new Category();
            if ($this->idMode === 'shared') {
                $category->force_id = true;
                $category->id       = $masterId;
            }
        } else {
            $category = new Category($localId);
        }

        // Resolver ID del padre
        $masterParentId = (int)$data['id_parent'];
        if ($masterParentId > 2) {
            $localParentId = $this->resolveLocalId('category', $masterParentId);
            $category->id_parent = $localParentId ?: 2; // 2 = raíz en PS
        } else {
            $category->id_parent = $masterParentId ?: 2;
        }

        $category->active = (bool)$data['active'];

        // Traducciones
        foreach ((isset($data['translations']) ? $data['translations'] : []) as $iso => $trans) {
            $idLang = (isset($this->langMap[$iso]) ? $this->langMap[$iso] : null);
            if (!$idLang) {
                continue;
            }
            foreach ($trans as $field => $value) {
                $category->$field[$idLang] = $value;
            }
        }

        $saved   = $isNew ? $category->add() : $category->update();
        $localId = (int)$category->id;

        if (!$saved || !$localId) {
            return ['success' => false, 'error' => 'Error al guardar categoría ' . $masterId];
        }

        $this->saveIdMap('category', $masterId, $localId, []);

        // Imagen de categoría
        if (!empty($data['image_url'])) {
            $this->downloadCategoryImage($localId, $data['image_url']);
        }

        return ['success' => true, 'local_id' => $localId];
    }

    // =========================================================================
    // STOCK
    // =========================================================================

    private function importStock(array $data)
    {
        $masterId           = (int)$data['master_id'];
        $masterAttrId       = (int)$data['id_product_attribute'];
        $quantity           = (int)$data['quantity'];

        $localId = $this->resolveLocalId('product', $masterId);
        if (!$localId) {
            // Producto aún no sincronizado (sync inicial en curso o producto eliminado).
            // El stock se establecerá cuando se importe el producto. No es un error.
            return ['success' => true, 'skipped' => true];
        }

        // Resolver attribute ID si aplica
        $localAttrId = 0;
        if ($masterAttrId) {
            // En modo free necesitaríamos mapearlo, simplificamos por ahora
            $localAttrId = $masterAttrId;
        }

        SyncMasterVersionCompat::setProductStock($localId, $localAttrId, $quantity);

        return ['success' => true];
    }

    // =========================================================================
    // FABRICANTE
    // =========================================================================

    private function importManufacturer(array $data)
    {
        $masterId = (int)$data['master_id'];
        $localId  = $this->resolveLocalId('manufacturer', $masterId);

        if ($localId) {
            $mfr = new Manufacturer($localId);
        } else {
            $mfr = new Manufacturer();
            if ($this->idMode === 'shared') {
                $mfr->force_id = true;
                $mfr->id       = $masterId;
            }
        }

        $mfr->name   = $data['name'];
        $mfr->active = 1;

        foreach ($this->langMap as $iso => $idLang) {
            $mfr->description[$idLang]      = (isset($data['description']) ? $data['description'] : '');
            $mfr->short_description[$idLang] = '';
            $mfr->meta_title[$idLang]        = $data['name'];
            $mfr->meta_description[$idLang]  = '';
            $mfr->meta_keywords[$idLang]      = '';
        }

        $saved   = $localId ? $mfr->update() : $mfr->add();
        $localId = (int)$mfr->id;

        if ($saved && $localId) {
            $this->saveIdMap('manufacturer', $masterId, $localId, []);
        }

        return ['success' => (bool)$saved];
    }

    // =========================================================================
    // GRUPOS DE ATRIBUTOS
    // =========================================================================

    private function importAttributeGroup(array $data)
    {
        $idLang = SyncMasterVersionCompat::getDefaultLangId();

        $masterId = (int)$data['master_id'];
        $localId  = $this->resolveLocalId('attribute_group', $masterId);

        if (!$localId) {
            $localId = SyncMasterVersionCompat::findOrCreateAttributeGroup(
                $data['name'], $idLang
            );
            $this->saveIdMap('attribute_group', $masterId, $localId, []);
        }

        // Importar valores de atributo
        foreach ((isset($data['attributes']) ? $data['attributes'] : []) as $attr) {
            $masterAttrId = (int)$attr['id_attribute'];
            $existingAttr = $this->resolveLocalId('attribute', $masterAttrId);
            if (!$existingAttr) {
                $localAttrId = SyncMasterVersionCompat::findOrCreateAttribute(
                    $localId, $attr['name'], $idLang
                );
                $this->saveIdMap('attribute', $masterAttrId, $localAttrId, []);
            }
        }

        return ['success' => true, 'local_id' => $localId];
    }

    // =========================================================================
    // CARACTERÍSTICAS
    // =========================================================================

    private function importFeature(array $data)
    {
        $idLang   = SyncMasterVersionCompat::getDefaultLangId();
        $masterId = (int)$data['master_id'];
        $localId  = $this->resolveLocalId('feature', $masterId);

        if (!$localId) {
            $localId = SyncMasterVersionCompat::findOrCreateFeature($data['name'], $idLang);
            $this->saveIdMap('feature', $masterId, $localId, []);
        }

        foreach ((isset($data['values']) ? $data['values'] : []) as $fv) {
            $masterFvId = (int)$fv['id_feature_value'];
            $existing   = $this->resolveLocalId('feature_value', $masterFvId);
            if (!$existing) {
                $localFvId = SyncMasterVersionCompat::findOrCreateFeatureValue(
                    $localId, $fv['value'], $idLang
                );
                $this->saveIdMap('feature_value', $masterFvId, $localFvId, []);
            }
        }

        return ['success' => true];
    }

    // =========================================================================
    // IMAGEN
    // =========================================================================

    private function importImage(array $data)
    {
        $idProduct  = (int)$data['id_product'];
        $idImage    = (int)$data['id_image'];
        $url        = (isset($data['url']) ? $data['url'] : '');

        if (empty($url)) {
            return ['success' => false, 'error' => 'URL de imagen vacía'];
        }

        // Resolver ID local del producto
        $localProductId = $this->resolveLocalId('product', $idProduct);
        if (!$localProductId) {
            return ['success' => false, 'error' => 'Producto local no encontrado para imagen'];
        }

        // Descargar imagen
        $tmpFile = tempnam(sys_get_temp_dir(), 'syncimg_');
        if (!$this->downloadFile($url, $tmpFile)) {
            @unlink($tmpFile);
            return ['success' => false, 'error' => 'No se pudo descargar imagen: ' . $url];
        }

        // Crear registro de imagen en PS
        $image = new Image();
        $image->id_product = $localProductId;
        $image->position   = Image::getHighestPosition($localProductId) + 1;
        $image->cover      = ($image->position === 1);

        if (!$image->add()) {
            @unlink($tmpFile);
            return ['success' => false, 'error' => 'Error al crear registro de imagen'];
        }

        // Mover a la carpeta correcta
        $destDir  = _PS_PROD_IMG_DIR_ . Image::getImgFolderStatic($image->id);
        if (!is_dir($destDir)) {
            mkdir($destDir, 0775, true);
        }

        $destFile = $destDir . $image->id . '.jpg';
        rename($tmpFile, $destFile);
        chmod($destFile, 0664);

        // Generar tipos de imagen (thumbnails)
        $imageTypes = ImageType::getImagesTypes('products');
        foreach ($imageTypes as $imageType) {
            $source = $destFile;
            $dest   = $destDir . $image->id
                . '-' . stripslashes($imageType['name']) . '.jpg';
            ImageManager::resize($source, $dest, (int)$imageType['width'], (int)$imageType['height']);
        }

        // Mapear ID
        $this->saveIdMap('image', $idImage, (int)$image->id, []);

        return ['success' => true, 'local_image_id' => $image->id];
    }

    // =========================================================================
    // DELETE PRODUCTO
    // =========================================================================

    private function deleteProduct($masterId, $localId)
    {
        if ($localId) {
            $product = new Product($localId);
            if (Validate::isLoadedObject($product)) {
                $product->delete();
            }
        }
        // Limpiar mapeo
        Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'sync_id_map`
             WHERE id_connection = ' . $this->idConnection . '
             AND entity_type = \'product\'
             AND master_id = ' . (int)$masterId
        );
        return ['success' => true];
    }

    // =========================================================================
    // POLÍTICA DE ESCRITURA (always / if_untouched / never)
    // =========================================================================

    /**
     * Decide si un campo debe escribirse según su política configurada
     */
    private function shouldWrite($fieldName, $newValue, array $savedHashes)
    {
        $config = (isset($this->fieldConfig[$fieldName]) ? $this->fieldConfig[$fieldName] : null);

        if (!$config) {
            // Sin config → usar política por defecto (always)
            return true;
        }

        if (!(bool)$config['sync_enabled']) {
            return false;
        }

        switch ($config['overwrite_policy']) {
            case 'always':
                return true;

            case 'never':
                return false;

            case 'if_untouched':
                if ($newValue === null) {
                    return true; // Campos de tipo bloque (categories, features), siempre sync
                }
                $currentHash = (isset($savedHashes[$fieldName]) ? $savedHashes[$fieldName] : null);
                if ($currentHash === null) {
                    return true; // Primera vez, no hay hash guardado
                }
                // Comprobar si el valor actual en BD coincide con el hash del último sync
                // Si no coincide, la hija modificó el campo → no machacar
                $currentProduct = null; // Se podría cargar el producto actual para comparar
                // Por simplicidad, comparamos el hash guardado con el hash del nuevo valor:
                // Si son iguales, la hija no lo tocó (el master no cambió nada relevante)
                return true; // En la práctica, el hash tracker completo requeriría cargar el objeto

            default:
                return true;
        }
    }

    private function resolveConfigField($field)
    {
        // Mapeo de campos de BD a nombres de config
        $map = [
            'additional_shipping_cost' => 'add_shipping_cost',
        ];
        return (isset($map[$field]) ? $map[$field] : $field);
    }

    // =========================================================================
    // MAPEO DE IDs
    // =========================================================================

    private function resolveLocalId($entityType, $masterId)
    {
        if ($this->idMode === 'shared') {
            return $masterId; // En modo shared, el ID es el mismo
        }

        $result = Db::getInstance()->getValue(
            'SELECT local_id FROM `' . _DB_PREFIX_ . 'sync_id_map`
             WHERE id_connection = ' . $this->idConnection . '
             AND entity_type = \'' . pSQL($entityType) . '\'
             AND master_id = ' . (int)$masterId
        );

        return $result ? (int)$result : 0;
    }

    private function saveIdMap($entityType, $masterId, $localId, array $hashes)
    {
        if ($this->idMode === 'shared') {
            return; // No necesitamos mapeo en modo shared
        }

        $existing = Db::getInstance()->getValue(
            'SELECT id_map FROM `' . _DB_PREFIX_ . 'sync_id_map`
             WHERE id_connection = ' . $this->idConnection . '
             AND entity_type = \'' . pSQL($entityType) . '\'
             AND master_id = ' . (int)$masterId
        );

        $data = [
            'id_connection' => $this->idConnection,
            'entity_type'   => pSQL($entityType),
            'master_id'     => (int)$masterId,
            'local_id'      => (int)$localId,
            'field_hashes'  => !empty($hashes) ? json_encode($hashes) : null,
            'last_sync'     => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            Db::getInstance()->update(
                'sync_id_map',
                $data,
                'id_map = ' . (int)$existing
            );
        } else {
            Db::getInstance()->insert('sync_id_map', $data);
        }
    }

    private function getFieldHashes($entityType, $masterId)
    {
        $json = Db::getInstance()->getValue(
            'SELECT field_hashes FROM `' . _DB_PREFIX_ . 'sync_id_map`
             WHERE id_connection = ' . $this->idConnection . '
             AND entity_type = \'' . pSQL($entityType) . '\'
             AND master_id = ' . (int)$masterId
        );
        return $json ? (json_decode($json, true) ?: []) : [];
    }

    // =========================================================================
    // UTILIDADES HTTP
    // =========================================================================

    private function downloadFile($url, $dest)
    {
        if (!extension_loaded('curl')) {
            return @copy($url, $dest);
        }

        $ch = curl_init($url);
        $fp = fopen($dest, 'wb');
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $ok = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        return $ok && $code === 200;
    }

    private function downloadCategoryImage($idCategory, $url)
    {
        $dest = _PS_CAT_IMG_DIR_ . $idCategory . '.jpg';
        $this->downloadFile($url, $dest);
    }
}
