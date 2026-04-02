<?php
/**
 * AdminSyncBaseController
 *
 * Solución definitiva al problema de carga de plantillas en PS 1.6 / 8 / 9:
 *
 * PS 8 hace: $smarty->fetch( themes/default/template/ + lo que pases a setTemplate() )
 * Si pasas una ruta absoluta, la concatena igualmente → ruta inválida doble.
 * setTemplate() es incompatible con rutas absolutas en PS 8 con AdminController legacy.
 *
 * SOLUCIÓN: no usar setTemplate() en absoluto.
 * Usamos $this->context->smarty->fetch(ruta_absoluta) directamente
 * y volcamos el resultado en $this->content.
 * PS lo imprimirá en el área central del admin sin tocar el template.
 *
 * SIN menú lateral: los controllers no tienen tab en el menú.
 * El módulo se accede desde Módulos → Configurar.
 * Esto evita todos los problemas de registro de tabs entre versiones.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

$_smBase = dirname(__FILE__) . '/../../classes/';
foreach (['SyncMasterHelpers', 'SyncMasterQueue', 'SyncMasterApi', 'SyncMasterVersionCompat'] as $_c) {
    if (!class_exists($_c) && file_exists($_smBase . $_c . '.php')) {
        require_once $_smBase . $_c . '.php';
    }
}
unset($_smBase, $_c);

abstract class AdminSyncBaseController extends ModuleAdminController
{
    protected $smTplDir;

    public function __construct()
    {
        parent::__construct();
        $this->bootstrap = true;
        $this->smTplDir  = _PS_MODULE_DIR_ . 'syncmaster/views/templates/admin/';
    }

    /**
     * Renderiza una plantilla del módulo inyectando el resultado en $this->content.
     * Compatible con PS 1.6, 1.7, 8 y 9.
     * No usa setTemplate() — evita la doble concatenación de rutas de PS 8.
     */
    protected function renderModuleTemplate($tplName)
    {
        $tplPath = $this->smTplDir . $tplName;

        if (!file_exists($tplPath)) {
            $this->content .= '<div class="alert alert-danger">'
                . 'SyncMaster: plantilla no encontrada: '
                . htmlspecialchars($tplPath)
                . '</div>';
            return;
        }

        // Añadir la carpeta al template_dir de Smarty para que resuelva includes internos
        $this->context->smarty->addTemplateDir($this->smTplDir);

        // fetch() con ruta absoluta funciona directamente en Smarty 2 (PS 1.6) y Smarty 3+ (PS 1.7+)
        // sin pasar por la lógica de resolución de AdminController
        $this->content .= $this->context->smarty->fetch($tplPath);
    }

    /**
     * URL del controller actual.
     */
    protected function selfUrl()
    {
        return $this->context->link->getAdminLink(get_class($this));
    }
}
