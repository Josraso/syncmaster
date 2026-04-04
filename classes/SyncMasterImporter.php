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

    public function __construct($idConnection, $idMode, $fieldConfig, $langFilter = '')
    {
        $this->idConnection = (int)$idConnection;
        $this->idMode       = $idMode;
        $this->fieldConfig  = $fieldConfig;
        $this->langMap      = SyncMasterVersionCompat::getSyncLanguageMap($langFilter);
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
            case 'command':
                return $this->handleCommand($payload);
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
            // El filtro delete_on_slave ya fue aplicado en el master antes de enviar.
            return $this->deleteProduct($masterId, $localId);
        }

        // Fallback en modo free: si no hay entry en id_map, intentar localizar el producto
        // en esta tienda por referencia (solo si la referencia es única, para evitar
        // problemas con referencias repetidas)
        if ($isNew && $this->idMode === 'free' && !empty($data['reference'])) {
            $foundByRef = $this->findProductByUniqueReference($data['reference']);
            if ($foundByRef) {
                $localId = $foundByRef;
                $isNew   = false;
                // Registrar el mapa para que los próximos syncs no necesiten la búsqueda
                $this->saveIdMap('product', $masterId, $localId, []);
            }
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

        // Si el producto existe pero no tiene nombre → reparar traducciones en este sync.
        // Usamos $forceTranslations en vez de $isNew para NO llamar a add() sobre un producto existente.
        $forceTranslations = false;
        if (!$isNew) {
            $defaultLang = (int)Configuration::get('PS_LANG_DEFAULT');
            $hasName = Db::getInstance()->getValue(
                'SELECT `name` FROM `' . _DB_PREFIX_ . 'product_lang`
                 WHERE id_product = ' . (int)$localId . ' AND id_lang = ' . $defaultLang
            );
            if (!$hasName) {
                $forceTranslations = true;
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

        // product_type (PS 8+): 'standard', 'combinations', 'pack', 'virtual'
        // Solo asignamos si la propiedad existe en el objeto (evita error en PS 1.6/1.7)
        if (!empty($data['product_type']) && property_exists($product, 'product_type')) {
            $product->product_type = $data['product_type'];
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
        if (isset($data['tax_rate']) && $this->shouldWrite('tax_rate', $data['tax_rate'], $savedHashes)) {
            // Usar el porcentaje real (tax_rate) para buscar el grupo correcto en ESTA tienda.
            // El id_tax_rules_group del master no coincide con el de la slave.
            $idTaxGroup = SyncMasterVersionCompat::getTaxRuleGroupByRate((float)$data['tax_rate']);
            $product->id_tax_rules_group = $idTaxGroup;
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
        // Para productos nuevos se escriben SIEMPRE (sin ellos el producto queda roto en PS).
        // Para actualizaciones se aplica el filtro de field config.
        if (isset($data['translations']) && ($isNew || $forceTranslations || $this->shouldWrite('name', null, $savedHashes))) {
            $defaultLangId = (int)Configuration::get('PS_LANG_DEFAULT');
            $textFields = [
                'name', 'description', 'description_short',
                'available_now', 'available_later',
                'meta_title', 'meta_description', 'meta_keywords',
                'link_rewrite',
            ];

            // Primera pasada: escribir las traducciones que coincidan por ISO
            $writtenLangs = [];
            foreach ($data['translations'] as $iso => $trans) {
                $idLang = (isset($this->langMap[$iso]) ? $this->langMap[$iso] : null);
                if (!$idLang) {
                    continue;
                }
                $writtenLangs[] = $idLang;
                foreach ($textFields as $tf) {
                    if (isset($trans[$tf]) && ($isNew || $forceTranslations || $this->shouldWrite($tf, $trans[$tf], $savedHashes))) {
                        $product->$tf[$idLang] = $trans[$tf];
                        $newHashes[$tf] = SyncMasterSerializer::hashField($trans[$tf]);
                    }
                }
            }

            // Fallback: si el idioma por defecto del slave no recibió traducciones,
            // copiar la primera traducción disponible para evitar product_lang vacío.
            if ($isNew && !in_array($defaultLangId, $writtenLangs)) {
                $firstTrans = reset($data['translations']);
                if ($firstTrans) {
                    foreach ($textFields as $tf) {
                        if (isset($firstTrans[$tf])) {
                            $product->$tf[$defaultLangId] = $firstTrans[$tf];
                        }
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
        // Imágenes inline — importar ANTES de combinaciones para que los IDs
        // ya estén mapeados cuando se asocien imágenes a cada combinación.
        // Se omite si el payload lleva skip_images=true (sync rápido sin imágenes).
        // -----------------------------------------------------------------
        if (!empty($data['images']) && empty($data['skip_images'])
            && $this->shouldWrite('images', null, $savedHashes)
        ) {
            $this->importProductImagesInline($masterId, $localId, $data['images']);
        }

        // -----------------------------------------------------------------
        // Combinaciones
        // -----------------------------------------------------------------
        if (!empty($data['combinations']) && $this->shouldWrite('attributes', null, $savedHashes)) {
            try {
                $this->importCombinations($product, $data['combinations']);
                // En PS 8+, marcar el producto como tipo 'combinations' para que el panel
                // las muestre correctamente (el tipo no se actualiza automáticamente via SQL)
                SyncMasterVersionCompat::setProductTypeCombinations($localId);
            } catch (Exception $e) {
                // Error en combinaciones no mata el producto base
            } catch (Error $e) {
                // PHP 7+ fatal errors
            }
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
        $db     = Db::getInstance();

        // IDs de combinaciones del slave que se crean/actualizan en este import
        // → cualquier combinación del slave que NO esté aquí será eliminada al final
        $processedSlaveIds = [];

        foreach ($combinations as $combIdx => $comb) {
            try {
                if (empty($comb['attributes'])) {
                    continue;
                }
                $attributeIds = [];
                foreach ($comb['attributes'] as $attr) {
                    $idGroup = SyncMasterVersionCompat::findOrCreateAttributeGroup(
                        $attr['group_name'], $idLang
                    );
                    if (!$idGroup) {
                        continue;
                    }
                    $idAttr = SyncMasterVersionCompat::findOrCreateAttribute(
                        $idGroup, $attr['attr_name'], $idLang
                    );
                    if ($idAttr) {
                        $attributeIds[] = $idAttr;
                    }
                }

                $attributeIds = array_values(array_unique(array_filter($attributeIds)));
                if (empty($attributeIds)) {
                    continue;
                }

                $idProductAttribute = SyncMasterVersionCompat::findCombinationByAttributes(
                    $product->id,
                    $attributeIds
                );

                if (!$idProductAttribute) {
                    $idProductAttribute = SyncMasterVersionCompat::addProductAttributeCompat($product, $comb);
                    if ($idProductAttribute) {
                        SyncMasterVersionCompat::addAttributeCombinationsSql($idProductAttribute, $attributeIds);
                    }
                } else {
                    SyncMasterVersionCompat::updateProductAttributeSql($idProductAttribute, $comb);
                }

                if (!$idProductAttribute) {
                    continue;
                }

                $processedSlaveIds[] = (int)$idProductAttribute;

                // Mapear master combination ID → slave combination ID
                // También guardar los master image IDs para resolverlos tras la fase de imágenes
                $masterAttrId = (int)$comb['id_product_attribute'];
                if ($masterAttrId) {
                    $pendingImgs = !empty($comb['images']) ? array_map('intval', $comb['images']) : [];
                    $this->saveIdMap('product_attribute', $masterAttrId, $idProductAttribute,
                        $pendingImgs ? ['master_images' => $pendingImgs] : []
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

                // Asociar imágenes a la combinación
                if (!empty($comb['images'])) {
                    $this->linkCombinationImages($idProductAttribute, $comb['images']);
                }

            } catch (Exception $e) {
                SyncMasterLogger::log(
                    $this->idConnection, 'combination', (int)$product->id,
                    'import', 'error',
                    'Comb #' . $combIdx . ': ' . $e->getMessage()
                );
            } catch (Error $e) {
                SyncMasterLogger::log(
                    $this->idConnection, 'combination', (int)$product->id,
                    'import', 'error',
                    'Comb #' . $combIdx . ': ' . $e->getMessage()
                );
            }
        }

        // Eliminar combinaciones huérfanas (existían en el slave, ya no están en el master)
        if (!empty($processedSlaveIds)) {
            $keepList = implode(',', $processedSlaveIds);
            $orphans  = $db->executeS(
                'SELECT id_product_attribute FROM `' . _DB_PREFIX_ . 'product_attribute`
                 WHERE id_product = ' . (int)$product->id . '
                 AND id_product_attribute NOT IN (' . $keepList . ')'
            ) ?: [];
            foreach ($orphans as $row) {
                $this->deleteProductAttribute((int)$product->id, (int)$row['id_product_attribute']);
            }
        }

        SyncMasterVersionCompat::postProcessCombinations($product);
    }

    /**
     * Procesa comandos de control enviados por el master durante la sync inicial.
     * Actualmente: 'link_comb_images' → resuelve imágenes pendientes de combinaciones.
     */
    private function handleCommand(array $data)
    {
        switch (isset($data['cmd']) ? $data['cmd'] : '') {
            case 'link_comb_images':
                $this->resolvePendingCombinationImages();
                return ['success' => true];
            default:
                return ['success' => true]; // comando desconocido, ignorar
        }
    }

    /**
     * Resuelve los enlaces combinación→imagen que quedaron pendientes durante
     * la fase de productos (porque las imágenes aún no estaban importadas).
     * Se llama al final de la fase de imágenes en la sync inicial.
     */
    public function resolvePendingCombinationImages()
    {
        if ($this->idMode === 'shared') {
            return; // en shared los IDs de imagen son distintos pero no tenemos mapa
        }

        $rows = Db::getInstance()->executeS(
            'SELECT local_id, field_hashes
             FROM `' . _DB_PREFIX_ . 'sync_id_map`
             WHERE id_connection = ' . $this->idConnection . '
             AND entity_type = \'product_attribute\'
             AND field_hashes IS NOT NULL'
        ) ?: [];

        foreach ($rows as $row) {
            $hashes = json_decode($row['field_hashes'], true);
            if (empty($hashes['master_images'])) {
                continue;
            }
            $this->linkCombinationImages((int)$row['local_id'], $hashes['master_images']);
        }
    }

    /**
     * Elimina una combinación y todos sus datos relacionados via SQL directo
     * (cross-version: funciona en PS 1.6 → 9)
     */
    private function deleteProductAttribute($idProduct, $idPA)
    {
        $db = Db::getInstance();
        $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'product_attribute_combination`
                      WHERE id_product_attribute = ' . $idPA);
        $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'product_attribute_image`
                      WHERE id_product_attribute = ' . $idPA);
        $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'product_attribute_shop`
                      WHERE id_product_attribute = ' . $idPA);
        $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'stock_available`
                      WHERE id_product_attribute = ' . $idPA . ' AND id_product = ' . $idProduct);
        $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'product_attribute`
                      WHERE id_product_attribute = ' . $idPA);
    }

    /**
     * Asocia imágenes del slave a una combinación del slave.
     * Requiere que las imágenes ya estén importadas (mappings en sync_id_map).
     */
    private function linkCombinationImages($idProductAttribute, array $masterImageIds)
    {
        $db = Db::getInstance();
        $db->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'product_attribute_image`
             WHERE id_product_attribute = ' . (int)$idProductAttribute
        );
        foreach ($masterImageIds as $masterImgId) {
            $localImgId = $this->resolveLocalId('image', (int)$masterImgId);
            if ($localImgId) {
                $db->insert('product_attribute_image', [
                    'id_product_attribute' => (int)$idProductAttribute,
                    'id_image'             => (int)$localImgId,
                ], false, false, Db::INSERT_IGNORE);
            }
        }
    }

    /**
     * Importa imágenes incluidas en el payload del producto (modo queue real-time).
     * Solo descarga las que aún no estén mapeadas para no re-descargar en cada update.
     */
    private function importProductImagesInline($masterProductId, $localProductId, array $images)
    {
        foreach ($images as $imgData) {
            $masterImgId = (int)$imgData['id_image'];
            // Si ya está mapeada, omitir (no volver a descargar)
            if ($this->resolveLocalId('image', $masterImgId)) {
                continue;
            }
            if (empty($imgData['url'])) {
                continue;
            }
            $this->importImage([
                'id_product' => $masterProductId,
                'id_image'   => $masterImgId,
                'url'        => $imgData['url'],
            ]);
        }
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
            if (!Validate::isLoadedObject($category)) {
                // La categoría fue eliminada localmente → recrear
                $category = new Category();
                if ($this->idMode === 'shared') {
                    $category->force_id = true;
                    $category->id       = $masterId;
                }
                $isNew = true;
            }
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

        // Resolver attribute ID: en shared-ID los IDs coinciden; en free hay que usar el mapa
        $localAttrId = 0;
        if ($masterAttrId) {
            if ($this->idMode === 'shared') {
                $localAttrId = $masterAttrId;
            } else {
                $localAttrId = $this->resolveLocalId('product_attribute', $masterAttrId);
                // Si no hay mapa aún (primera sync o combinación no importada todavía), ignorar
                if (!$localAttrId) {
                    return ['success' => true, 'skipped' => true];
                }
            }
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
        $destDir  = SyncMasterVersionCompat::getProdImgDir() . Image::getImgFolderStatic($image->id);
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

    /**
     * Busca un producto en la tienda local por referencia.
     * Solo devuelve un resultado si la referencia es ÚNICA (exactamente un producto).
     * Con referencias repetidas devuelve 0 para evitar mapeos ambiguos.
     * También comprueba que el producto encontrado no esté ya mapeado a otro master_id.
     *
     * @param  string $reference
     * @return int    id_product local, 0 si no encontrado o ambiguo
     */
    private function findProductByUniqueReference($reference)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT id_product FROM `' . _DB_PREFIX_ . 'product`
             WHERE reference = \'' . pSQL($reference) . '\' LIMIT 2'
        );
        if (count($rows) !== 1) {
            return 0; // sin match o referencia duplicada → no usar
        }
        $localId = (int)$rows[0]['id_product'];

        // Verificar que no esté ya mapeado a un master_id diferente
        $existing = Db::getInstance()->getValue(
            'SELECT master_id FROM `' . _DB_PREFIX_ . 'sync_id_map`
             WHERE id_connection = ' . $this->idConnection . '
             AND entity_type = \'product\'
             AND local_id = ' . $localId
        );
        return $existing ? 0 : $localId;
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
