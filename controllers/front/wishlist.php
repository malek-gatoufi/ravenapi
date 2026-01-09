<?php
/**
 * RavenAPI - Wishlist Controller
 * Gestion des favoris utilisateur
 */

class RavenapiWishlistModuleFrontController extends ModuleFrontController
{
    public $auth = true;
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
        
        if (!$this->context->customer->isLogged()) {
            $this->jsonResponse(['error' => 'Non authentifié'], 401);
            return;
        }
        
        $method = $_SERVER['REQUEST_METHOD'];
        
        switch ($method) {
            case 'GET':
                $this->getWishlist();
                break;
            case 'POST':
                $this->addToWishlist();
                break;
            case 'DELETE':
                $this->removeFromWishlist();
                break;
            default:
                $this->jsonResponse(['error' => 'Méthode non supportée'], 405);
        }
    }
    
    /**
     * Récupérer la wishlist du client
     */
    private function getWishlist()
    {
        $customerId = (int)$this->context->customer->id;
        $langId = (int)$this->context->language->id;
        
        // Créer la table si elle n'existe pas
        $this->ensureWishlistTable();
        
        // Récupérer les produits de la wishlist
        $sql = '
            SELECT w.id_wishlist, w.id_product, w.id_product_attribute, w.date_add,
                   p.reference, p.price, p.quantity as stock,
                   pl.name, pl.link_rewrite, pl.description_short,
                   m.name as manufacturer_name,
                   cl.name as category_name,
                   i.id_image
            FROM `'._DB_PREFIX_.'raven_wishlist` w
            LEFT JOIN `'._DB_PREFIX_.'product` p ON w.id_product = p.id_product
            LEFT JOIN `'._DB_PREFIX_.'product_lang` pl ON (p.id_product = pl.id_product AND pl.id_lang = '.$langId.')
            LEFT JOIN `'._DB_PREFIX_.'manufacturer` m ON p.id_manufacturer = m.id_manufacturer
            LEFT JOIN `'._DB_PREFIX_.'category_lang` cl ON (p.id_category_default = cl.id_category AND cl.id_lang = '.$langId.')
            LEFT JOIN `'._DB_PREFIX_.'image` i ON (p.id_product = i.id_product AND i.cover = 1)
            WHERE w.id_customer = '.$customerId.'
            AND p.active = 1
            ORDER BY w.date_add DESC
        ';
        
        $wishlistItems = Db::getInstance()->executeS($sql);
        
        $products = [];
        foreach ($wishlistItems as $item) {
            $product = new Product((int)$item['id_product'], false, $langId);
            $price = Product::getPriceStatic((int)$item['id_product'], true, (int)$item['id_product_attribute']);
            $priceWithoutReduction = Product::getPriceStatic((int)$item['id_product'], true, (int)$item['id_product_attribute'], 6, null, false, false);
            
            // Image
            $imageUrl = null;
            if ($item['id_image']) {
                $image = new Image((int)$item['id_image']);
                $imageUrl = $this->context->link->getImageLink($item['link_rewrite'], $item['id_image'], 'home_default');
            }
            
            $products[] = [
                'id_wishlist' => (int)$item['id_wishlist'],
                'id_product' => (int)$item['id_product'],
                'id_product_attribute' => (int)$item['id_product_attribute'],
                'name' => $item['name'],
                'reference' => $item['reference'],
                'link_rewrite' => $item['link_rewrite'],
                'price' => round($price, 2),
                'price_without_reduction' => round($priceWithoutReduction, 2),
                'on_sale' => $price < $priceWithoutReduction,
                'reduction' => $priceWithoutReduction > 0 ? round(1 - ($price / $priceWithoutReduction), 2) : 0,
                'quantity' => (int)$item['stock'],
                'available' => (int)$item['stock'] > 0,
                'image' => $imageUrl,
                'manufacturer' => $item['manufacturer_name'],
                'category' => $item['category_name'],
                'url' => $this->context->link->getProductLink((int)$item['id_product']),
                'date_added' => $item['date_add'],
            ];
        }
        
        $this->jsonResponse([
            'success' => true,
            'wishlist' => $products,
            'count' => count($products),
        ]);
    }
    
    /**
     * Ajouter un produit à la wishlist
     */
    private function addToWishlist()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        
        $productId = isset($input['id_product']) ? (int)$input['id_product'] : 0;
        $attributeId = isset($input['id_product_attribute']) ? (int)$input['id_product_attribute'] : 0;
        
        if (!$productId) {
            $this->jsonResponse(['error' => 'ID produit requis'], 400);
            return;
        }
        
        // Vérifier que le produit existe
        $product = new Product($productId);
        if (!Validate::isLoadedObject($product) || !$product->active) {
            $this->jsonResponse(['error' => 'Produit non trouvé'], 404);
            return;
        }
        
        $customerId = (int)$this->context->customer->id;
        
        // Vérifier si déjà dans la wishlist
        $exists = Db::getInstance()->getValue('
            SELECT id_wishlist FROM `'._DB_PREFIX_.'raven_wishlist`
            WHERE id_customer = '.$customerId.'
            AND id_product = '.$productId.'
            AND id_product_attribute = '.$attributeId
        );
        
        if ($exists) {
            $this->jsonResponse([
                'success' => true,
                'message' => 'Produit déjà dans vos favoris',
                'already_exists' => true,
            ]);
            return;
        }
        
        // Ajouter à la wishlist
        $result = Db::getInstance()->insert('raven_wishlist', [
            'id_customer' => $customerId,
            'id_product' => $productId,
            'id_product_attribute' => $attributeId,
            'date_add' => date('Y-m-d H:i:s'),
        ]);
        
        if ($result) {
            // Compter le total
            $count = (int)Db::getInstance()->getValue('
                SELECT COUNT(*) FROM `'._DB_PREFIX_.'raven_wishlist`
                WHERE id_customer = '.$customerId
            );
            
            $this->jsonResponse([
                'success' => true,
                'message' => 'Produit ajouté aux favoris',
                'count' => $count,
            ]);
        } else {
            $this->jsonResponse(['error' => 'Erreur lors de l\'ajout'], 500);
        }
    }
    
    /**
     * Supprimer de la wishlist
     */
    private function removeFromWishlist()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        
        $productId = isset($input['id_product']) ? (int)$input['id_product'] : 0;
        $attributeId = isset($input['id_product_attribute']) ? (int)$input['id_product_attribute'] : 0;
        $wishlistId = isset($input['id_wishlist']) ? (int)$input['id_wishlist'] : 0;
        
        $customerId = (int)$this->context->customer->id;
        
        if ($wishlistId) {
            // Supprimer par ID wishlist
            $result = Db::getInstance()->delete('raven_wishlist', 
                'id_wishlist = '.$wishlistId.' AND id_customer = '.$customerId
            );
        } elseif ($productId) {
            // Supprimer par ID produit
            $result = Db::getInstance()->delete('raven_wishlist',
                'id_customer = '.$customerId.
                ' AND id_product = '.$productId.
                ' AND id_product_attribute = '.$attributeId
            );
        } else {
            $this->jsonResponse(['error' => 'ID produit ou wishlist requis'], 400);
            return;
        }
        
        if ($result) {
            $count = (int)Db::getInstance()->getValue('
                SELECT COUNT(*) FROM `'._DB_PREFIX_.'raven_wishlist`
                WHERE id_customer = '.$customerId
            );
            
            $this->jsonResponse([
                'success' => true,
                'message' => 'Produit retiré des favoris',
                'count' => $count,
            ]);
        } else {
            $this->jsonResponse(['error' => 'Produit non trouvé dans les favoris'], 404);
        }
    }
    
    /**
     * Créer la table wishlist si nécessaire
     */
    private function ensureWishlistTable()
    {
        $sql = '
            CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'raven_wishlist` (
                `id_wishlist` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_customer` INT(11) UNSIGNED NOT NULL,
                `id_product` INT(11) UNSIGNED NOT NULL,
                `id_product_attribute` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_wishlist`),
                KEY `id_customer` (`id_customer`),
                KEY `id_product` (`id_product`),
                UNIQUE KEY `customer_product` (`id_customer`, `id_product`, `id_product_attribute`)
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
