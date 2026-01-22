<?php
/**
 * AJAX endpoint pour récupérer la source du frontend d'une commande
 * Utilisé par le back-office pour afficher le badge visuel
 */

require_once dirname(__FILE__) . '/../../../config/config.inc.php';
require_once dirname(__FILE__) . '/../../../init.php';

header('Content-Type: application/json');

// Vérifier que l'utilisateur est connecté en tant qu'admin
if (!Context::getContext()->employee || !Context::getContext()->employee->id) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$id_order = (int) Tools::getValue('id_order');

if (!$id_order) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing id_order']);
    exit;
}

try {
    // Récupérer la source du frontend depuis la base
    $frontend_source = Db::getInstance()->getValue('
        SELECT frontend_source 
        FROM ' . _DB_PREFIX_ . 'orders 
        WHERE id_order = ' . (int) $id_order
    );
    
    // Par défaut, si la colonne n'existe pas ou est vide, c'est "classic"
    if (empty($frontend_source)) {
        $frontend_source = 'classic';
    }
    
    echo json_encode([
        'id_order' => $id_order,
        'frontend_source' => $frontend_source
    ]);
    
} catch (Exception $e) {
    PrestaShopLogger::addLog('AJAX frontend source error: ' . $e->getMessage(), 3);
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'frontend_source' => 'classic' // Fallback
    ]);
}
