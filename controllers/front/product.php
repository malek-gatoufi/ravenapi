<?php
/**
 * API Product Controller
 * GET /api/v1/products/{id}
 */

require_once _PS_MODULE_DIR_ . 'ravenapi/classes/RavenApiBaseController.php';

class RavenapiProductModuleFrontController extends RavenApiBaseModuleFrontController
{
    public function initContent()
    {
        parent::initContent();

        if ($this->getMethod() !== 'GET') {
            $this->sendError('Method not allowed', 405);
        }

        $this->getProduct();
    }

    protected function getProduct()
    {
        $idOrSlug = Tools::getValue('id');

        if (empty($idOrSlug)) {
            $this->sendError('Product ID or slug required', 400);
        }

        // Recherche par ID ou slug
        if (is_numeric($idOrSlug)) {
            $product = new Product((int) $idOrSlug, true, $this->id_lang);
        } else {
            // Recherche par link_rewrite
            $sql = 'SELECT p.id_product 
                    FROM ' . _DB_PREFIX_ . 'product p
                    INNER JOIN ' . _DB_PREFIX_ . 'product_lang pl ON p.id_product = pl.id_product
                    WHERE pl.link_rewrite = "' . pSQL($idOrSlug) . '" 
                    AND pl.id_lang = ' . (int) $this->id_lang . '
                    LIMIT 1';
            $productId = Db::getInstance()->getValue($sql);
            
            if (!$productId) {
                $this->sendError('Product not found', 404);
            }
            
            $product = new Product((int) $productId, true, $this->id_lang);
        }

        if (!Validate::isLoadedObject($product) || !$product->active) {
            $this->sendError('Product not found', 404);
        }

        // Vérifier la visibilité
        $productShop = new ProductShop();
        if (!Product::isAvailableWhenOutOfStock($product->out_of_stock) 
            && !StockAvailable::getQuantityAvailableByProduct($product->id)) {
            // Produit indisponible mais on le montre quand même
        }

        // Récupérer les produits similaires
        $relatedProducts = $this->getRelatedProducts($product);

        $data = $this->formatProduct($product, true);
        $data['related_products'] = $relatedProducts;

        // Breadcrumb
        $data['breadcrumb'] = $this->getBreadcrumb($product);

        $this->sendResponse($data);
    }

    /**
     * Récupère les produits similaires
     */
    protected function getRelatedProducts(Product $product): array
    {
        $related = [];
        
        // Produits de la même catégorie
        $categoryProducts = Product::getProducts(
            $this->id_lang,
            0,
            8,
            'date_add',
            'DESC',
            $product->id_category_default,
            true
        );

        foreach ($categoryProducts as $relatedProduct) {
            if ((int) $relatedProduct['id_product'] !== (int) $product->id) {
                $p = new Product($relatedProduct['id_product'], false, $this->id_lang);
                if (Validate::isLoadedObject($p)) {
                    $related[] = $this->formatProduct($p);
                }
            }
            if (count($related) >= 4) {
                break;
            }
        }

        return $related;
    }

    /**
     * Génère le breadcrumb
     */
    protected function getBreadcrumb(Product $product): array
    {
        $breadcrumb = [
            ['name' => 'Accueil', 'url' => '/'],
        ];

        // Catégorie parente
        $category = new Category($product->id_category_default, $this->id_lang);
        if (Validate::isLoadedObject($category)) {
            $parents = $category->getParentsCategories($this->id_lang);
            $parents = array_reverse($parents);
            
            foreach ($parents as $parent) {
                if ((int) $parent['id_category'] > 2) { // Skip root and home
                    $breadcrumb[] = [
                        'name' => $parent['name'],
                        'url' => '/category/' . $parent['link_rewrite'],
                    ];
                }
            }
        }

        // Produit actuel
        $breadcrumb[] = [
            'name' => $product->name[$this->id_lang] ?? $product->name,
            'url' => null,
        ];

        return $breadcrumb;
    }
}
