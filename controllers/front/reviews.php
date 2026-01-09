<?php
/**
 * RavenAPI - Reviews Controller
 * Gestion des avis clients
 */

class RavenapiReviewsModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    
    public function init()
    {
        parent::init();
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: https://new.ravenindustries.fr');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
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
                $this->getReviews();
                break;
            case 'POST':
                $this->addReview();
                break;
            default:
                $this->jsonResponse(['error' => 'Méthode non supportée'], 405);
        }
    }
    
    /**
     * Récupérer les avis d'un produit
     */
    private function getReviews()
    {
        $productId = (int)Tools::getValue('id_product', 0);
        
        if (!$productId) {
            $this->jsonResponse(['error' => 'ID produit requis'], 400);
            return;
        }
        
        // Créer la table si nécessaire
        $this->ensureReviewsTable();
        
        $langId = (int)$this->context->language->id;
        
        // Récupérer les avis approuvés
        $sql = '
            SELECT r.id_review, r.id_customer, r.rating, r.title, r.content, 
                   r.date_add, r.helpful_yes, r.helpful_no,
                   c.firstname, c.lastname
            FROM `'._DB_PREFIX_.'raven_reviews` r
            LEFT JOIN `'._DB_PREFIX_.'customer` c ON r.id_customer = c.id_customer
            WHERE r.id_product = '.$productId.'
            AND r.validated = 1
            ORDER BY r.date_add DESC
        ';
        
        $reviews = Db::getInstance()->executeS($sql);
        
        // Calculer les stats
        $totalReviews = count($reviews);
        $avgRating = 0;
        $ratingDistribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        
        if ($totalReviews > 0) {
            $sumRating = 0;
            foreach ($reviews as $review) {
                $sumRating += (int)$review['rating'];
                $ratingDistribution[(int)$review['rating']]++;
            }
            $avgRating = round($sumRating / $totalReviews, 1);
        }
        
        // Formater les avis
        $formattedReviews = [];
        foreach ($reviews as $review) {
            // Masquer partiellement le nom
            $displayName = $review['firstname'];
            if (!empty($review['lastname'])) {
                $displayName .= ' ' . substr($review['lastname'], 0, 1) . '.';
            }
            
            $formattedReviews[] = [
                'id' => (int)$review['id_review'],
                'rating' => (int)$review['rating'],
                'title' => $review['title'],
                'content' => $review['content'],
                'author' => $displayName,
                'date' => $review['date_add'],
                'helpful' => [
                    'yes' => (int)$review['helpful_yes'],
                    'no' => (int)$review['helpful_no'],
                ],
                'verified_purchase' => $this->isVerifiedPurchase($productId, (int)$review['id_customer']),
            ];
        }
        
        // Vérifier si le client connecté peut laisser un avis
        $canReview = false;
        $hasReviewed = false;
        if ($this->context->customer->isLogged()) {
            $customerId = (int)$this->context->customer->id;
            
            // A-t-il déjà laissé un avis ?
            $hasReviewed = (bool)Db::getInstance()->getValue('
                SELECT id_review FROM `'._DB_PREFIX_.'raven_reviews`
                WHERE id_product = '.$productId.'
                AND id_customer = '.$customerId
            );
            
            // A-t-il acheté ce produit ?
            $canReview = !$hasReviewed && $this->isVerifiedPurchase($productId, $customerId);
        }
        
        $this->jsonResponse([
            'success' => true,
            'reviews' => $formattedReviews,
            'stats' => [
                'total' => $totalReviews,
                'average' => $avgRating,
                'distribution' => $ratingDistribution,
            ],
            'can_review' => $canReview,
            'has_reviewed' => $hasReviewed,
        ]);
    }
    
    /**
     * Ajouter un avis
     */
    private function addReview()
    {
        if (!$this->context->customer->isLogged()) {
            $this->jsonResponse(['error' => 'Connexion requise pour laisser un avis'], 401);
            return;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $productId = isset($input['id_product']) ? (int)$input['id_product'] : 0;
        $rating = isset($input['rating']) ? (int)$input['rating'] : 0;
        $title = isset($input['title']) ? trim($input['title']) : '';
        $content = isset($input['content']) ? trim($input['content']) : '';
        
        // Validations
        if (!$productId) {
            $this->jsonResponse(['error' => 'ID produit requis'], 400);
            return;
        }
        
        if ($rating < 1 || $rating > 5) {
            $this->jsonResponse(['error' => 'Note entre 1 et 5 requise'], 400);
            return;
        }
        
        if (empty($content) || strlen($content) < 10) {
            $this->jsonResponse(['error' => 'Avis trop court (minimum 10 caractères)'], 400);
            return;
        }
        
        if (strlen($content) > 2000) {
            $this->jsonResponse(['error' => 'Avis trop long (maximum 2000 caractères)'], 400);
            return;
        }
        
        $customerId = (int)$this->context->customer->id;
        
        // Vérifier si déjà un avis
        $exists = Db::getInstance()->getValue('
            SELECT id_review FROM `'._DB_PREFIX_.'raven_reviews`
            WHERE id_product = '.$productId.'
            AND id_customer = '.$customerId
        );
        
        if ($exists) {
            $this->jsonResponse(['error' => 'Vous avez déjà laissé un avis pour ce produit'], 400);
            return;
        }
        
        // Vérifier l'achat
        $verified = $this->isVerifiedPurchase($productId, $customerId);
        
        // Insérer l'avis
        $result = Db::getInstance()->insert('raven_reviews', [
            'id_product' => $productId,
            'id_customer' => $customerId,
            'rating' => $rating,
            'title' => pSQL($title),
            'content' => pSQL($content),
            'validated' => $verified ? 1 : 0, // Auto-validation si achat vérifié
            'helpful_yes' => 0,
            'helpful_no' => 0,
            'date_add' => date('Y-m-d H:i:s'),
        ]);
        
        if ($result) {
            $this->jsonResponse([
                'success' => true,
                'message' => $verified 
                    ? 'Merci pour votre avis ! Il est maintenant visible.'
                    : 'Merci pour votre avis ! Il sera visible après modération.',
                'validated' => $verified,
            ]);
        } else {
            $this->jsonResponse(['error' => 'Erreur lors de l\'enregistrement'], 500);
        }
    }
    
    /**
     * Vérifier si le client a acheté ce produit
     */
    private function isVerifiedPurchase($productId, $customerId)
    {
        $sql = '
            SELECT od.id_order 
            FROM `'._DB_PREFIX_.'order_detail` od
            JOIN `'._DB_PREFIX_.'orders` o ON od.id_order = o.id_order
            WHERE od.product_id = '.(int)$productId.'
            AND o.id_customer = '.(int)$customerId.'
            AND o.valid = 1
            LIMIT 1
        ';
        return (bool)Db::getInstance()->getValue($sql);
    }
    
    /**
     * Créer la table reviews
     */
    private function ensureReviewsTable()
    {
        $sql = '
            CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'raven_reviews` (
                `id_review` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_product` INT(11) UNSIGNED NOT NULL,
                `id_customer` INT(11) UNSIGNED NOT NULL,
                `rating` TINYINT(1) NOT NULL,
                `title` VARCHAR(255) DEFAULT NULL,
                `content` TEXT NOT NULL,
                `validated` TINYINT(1) NOT NULL DEFAULT 0,
                `helpful_yes` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `helpful_no` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_review`),
                KEY `id_product` (`id_product`),
                KEY `id_customer` (`id_customer`),
                KEY `validated` (`validated`)
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
