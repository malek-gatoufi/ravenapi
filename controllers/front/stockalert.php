<?php
/**
 * RavenAPI - Stock Alert Controller
 * Inscription aux alertes de disponibilité produit
 */

class RavenapiStockalertModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    
    public function init()
    {
        parent::init();
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: https://new.ravenindustries.fr');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
    }
    
    public function initContent()
    {
        parent::initContent();
        
        $method = $_SERVER['REQUEST_METHOD'];
        
        switch ($method) {
            case 'GET':
                $this->getAlerts();
                break;
            case 'POST':
                $this->subscribeAlert();
                break;
            case 'DELETE':
                $this->unsubscribeAlert();
                break;
            default:
                $this->jsonResponse(['error' => 'Méthode non supportée'], 405);
        }
    }
    
    /**
     * Récupérer les alertes de l'utilisateur
     */
    private function getAlerts()
    {
        // Créer la table si nécessaire
        $this->ensureAlertsTable();
        
        // Soit par customer ID, soit par email pour les non-connectés
        $customerId = $this->context->customer->isLogged() ? (int)$this->context->customer->id : 0;
        $email = Tools::getValue('email', '');
        $productId = (int)Tools::getValue('id_product', 0);
        
        // Vérifier si abonné à un produit spécifique
        if ($productId) {
            $subscribed = false;
            
            if ($customerId) {
                $subscribed = (bool)Db::getInstance()->getValue('
                    SELECT id_alert FROM `'._DB_PREFIX_.'raven_stock_alerts`
                    WHERE id_product = '.$productId.'
                    AND id_customer = '.$customerId.'
                    AND notified = 0
                ');
            } elseif ($email && Validate::isEmail($email)) {
                $subscribed = (bool)Db::getInstance()->getValue('
                    SELECT id_alert FROM `'._DB_PREFIX_.'raven_stock_alerts`
                    WHERE id_product = '.$productId.'
                    AND customer_email = "'.pSQL($email).'"
                    AND notified = 0
                ');
            }
            
            $this->jsonResponse([
                'success' => true,
                'subscribed' => $subscribed,
            ]);
            return;
        }
        
        // Récupérer toutes les alertes du client
        if (!$customerId) {
            $this->jsonResponse(['error' => 'Connexion requise'], 401);
            return;
        }
        
        $langId = (int)$this->context->language->id;
        
        $sql = '
            SELECT a.id_alert, a.id_product, a.id_product_attribute, a.notified, a.date_add,
                   pl.name, pl.link_rewrite,
                   p.reference, p.quantity as stock,
                   i.id_image
            FROM `'._DB_PREFIX_.'raven_stock_alerts` a
            LEFT JOIN `'._DB_PREFIX_.'product` p ON a.id_product = p.id_product
            LEFT JOIN `'._DB_PREFIX_.'product_lang` pl ON (p.id_product = pl.id_product AND pl.id_lang = '.$langId.')
            LEFT JOIN `'._DB_PREFIX_.'image` i ON (p.id_product = i.id_product AND i.cover = 1)
            WHERE a.id_customer = '.$customerId.'
            ORDER BY a.date_add DESC
        ';
        
        $alerts = Db::getInstance()->executeS($sql);
        
        $formattedAlerts = [];
        foreach ($alerts as $alert) {
            $imageUrl = null;
            if ($alert['id_image']) {
                $imageUrl = $this->context->link->getImageLink($alert['link_rewrite'], $alert['id_image'], 'small_default');
            }
            
            $formattedAlerts[] = [
                'id' => (int)$alert['id_alert'],
                'id_product' => (int)$alert['id_product'],
                'id_product_attribute' => (int)$alert['id_product_attribute'],
                'name' => $alert['name'],
                'reference' => $alert['reference'],
                'link_rewrite' => $alert['link_rewrite'],
                'image' => $imageUrl,
                'stock' => (int)$alert['stock'],
                'available' => (int)$alert['stock'] > 0,
                'notified' => (bool)$alert['notified'],
                'date_add' => $alert['date_add'],
            ];
        }
        
        $this->jsonResponse([
            'success' => true,
            'alerts' => $formattedAlerts,
            'count' => count($formattedAlerts),
        ]);
    }
    
    /**
     * S'abonner à une alerte stock
     */
    private function subscribeAlert()
    {
        $this->ensureAlertsTable();
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $productId = isset($input['id_product']) ? (int)$input['id_product'] : 0;
        $attributeId = isset($input['id_product_attribute']) ? (int)$input['id_product_attribute'] : 0;
        $email = isset($input['email']) ? trim($input['email']) : '';
        
        if (!$productId) {
            $this->jsonResponse(['error' => 'ID produit requis'], 400);
            return;
        }
        
        // Vérifier que le produit existe et est en rupture
        $product = new Product($productId);
        if (!Validate::isLoadedObject($product)) {
            $this->jsonResponse(['error' => 'Produit non trouvé'], 404);
            return;
        }
        
        $stock = StockAvailable::getQuantityAvailableByProduct($productId, $attributeId);
        if ($stock > 0) {
            $this->jsonResponse(['error' => 'Ce produit est déjà en stock'], 400);
            return;
        }
        
        $customerId = $this->context->customer->isLogged() ? (int)$this->context->customer->id : 0;
        
        // Si pas connecté, email requis
        if (!$customerId) {
            if (empty($email) || !Validate::isEmail($email)) {
                $this->jsonResponse(['error' => 'Email valide requis'], 400);
                return;
            }
        } else {
            $email = $this->context->customer->email;
        }
        
        // Vérifier si déjà abonné
        $whereClause = 'id_product = '.$productId.' AND id_product_attribute = '.$attributeId.' AND notified = 0';
        if ($customerId) {
            $whereClause .= ' AND id_customer = '.$customerId;
        } else {
            $whereClause .= ' AND customer_email = "'.pSQL($email).'"';
        }
        
        $exists = Db::getInstance()->getValue('
            SELECT id_alert FROM `'._DB_PREFIX_.'raven_stock_alerts`
            WHERE '.$whereClause
        );
        
        if ($exists) {
            $this->jsonResponse([
                'success' => true,
                'message' => 'Vous êtes déjà abonné à cette alerte',
                'already_subscribed' => true,
            ]);
            return;
        }
        
        // Créer l'alerte
        $result = Db::getInstance()->insert('raven_stock_alerts', [
            'id_product' => $productId,
            'id_product_attribute' => $attributeId,
            'id_customer' => $customerId,
            'customer_email' => pSQL($email),
            'notified' => 0,
            'date_add' => date('Y-m-d H:i:s'),
        ]);
        
        if ($result) {
            $this->jsonResponse([
                'success' => true,
                'message' => 'Vous serez prévenu dès que ce produit sera disponible',
            ]);
        } else {
            $this->jsonResponse(['error' => 'Erreur lors de l\'inscription'], 500);
        }
    }
    
    /**
     * Se désabonner d'une alerte
     */
    private function unsubscribeAlert()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        
        $alertId = isset($input['id_alert']) ? (int)$input['id_alert'] : 0;
        $productId = isset($input['id_product']) ? (int)$input['id_product'] : 0;
        $email = isset($input['email']) ? trim($input['email']) : '';
        
        $customerId = $this->context->customer->isLogged() ? (int)$this->context->customer->id : 0;
        
        if ($alertId) {
            // Supprimer par ID (vérifie que l'alerte appartient au client)
            if ($customerId) {
                $result = Db::getInstance()->delete('raven_stock_alerts',
                    'id_alert = '.$alertId.' AND id_customer = '.$customerId
                );
            } else {
                $this->jsonResponse(['error' => 'Connexion requise'], 401);
                return;
            }
        } elseif ($productId) {
            // Supprimer par produit
            if ($customerId) {
                $result = Db::getInstance()->delete('raven_stock_alerts',
                    'id_product = '.$productId.' AND id_customer = '.$customerId.' AND notified = 0'
                );
            } elseif ($email && Validate::isEmail($email)) {
                $result = Db::getInstance()->delete('raven_stock_alerts',
                    'id_product = '.$productId.' AND customer_email = "'.pSQL($email).'" AND notified = 0'
                );
            } else {
                $this->jsonResponse(['error' => 'Email ou connexion requise'], 400);
                return;
            }
        } else {
            $this->jsonResponse(['error' => 'ID alerte ou produit requis'], 400);
            return;
        }
        
        if ($result) {
            $this->jsonResponse([
                'success' => true,
                'message' => 'Alerte supprimée',
            ]);
        } else {
            $this->jsonResponse(['error' => 'Alerte non trouvée'], 404);
        }
    }
    
    /**
     * Créer la table des alertes
     */
    private function ensureAlertsTable()
    {
        $sql = '
            CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'raven_stock_alerts` (
                `id_alert` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_product` INT(11) UNSIGNED NOT NULL,
                `id_product_attribute` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `id_customer` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `customer_email` VARCHAR(255) NOT NULL,
                `notified` TINYINT(1) NOT NULL DEFAULT 0,
                `date_add` DATETIME NOT NULL,
                `date_notified` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id_alert`),
                KEY `id_product` (`id_product`),
                KEY `id_customer` (`id_customer`),
                KEY `notified` (`notified`)
            ) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8mb4
        ';
        Db::getInstance()->execute($sql);
    }
    
    /**
     * Réponse JSON
     */
    private function jsonResponse($data, $code = 200)
    {
        http_response_code($code);
        die(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
