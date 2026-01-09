<?php
/**
 * API Categories Controller
 * GET /api/v1/categories
 */

require_once _PS_MODULE_DIR_ . 'ravenapi/classes/RavenApiBaseController.php';

class RavenapiCategoriesModuleFrontController extends RavenApiBaseModuleFrontController
{
    public function initContent()
    {
        parent::initContent();

        if ($this->getMethod() !== 'GET') {
            $this->sendError('Method not allowed', 405);
        }

        $this->getCategories();
    }

    protected function getCategories()
    {
        $parentId = (int) $this->getParam('parent', 0);
        $flat = $this->getParam('flat', 'false') === 'true';
        $withProducts = $this->getParam('with_products', 'false') === 'true';

        if ($parentId === 0) {
            // Récupérer la catégorie racine (Home)
            $parentId = (int) Configuration::get('PS_HOME_CATEGORY');
        }

        if ($flat) {
            $categories = $this->getAllCategories();
        } else {
            $categories = $this->getCategoryTree($parentId, $withProducts);
        }

        $this->sendResponse(['data' => $categories]);
    }

    /**
     * Récupère toutes les catégories en liste plate
     */
    protected function getAllCategories(): array
    {
        $categories = Category::getCategories($this->id_lang, true, false);
        $result = [];

        foreach ($categories as $catId => $category) {
            if (isset($category['infos'])) {
                $cat = new Category($catId, $this->id_lang);
                if (Validate::isLoadedObject($cat) && $cat->active && $cat->id > 2) {
                    $result[] = $this->formatCategory($cat);
                }
            }
        }

        return $result;
    }

    /**
     * Récupère l'arbre des catégories
     */
    protected function getCategoryTree(int $parentId, bool $withProducts): array
    {
        $children = Category::getChildren($parentId, $this->id_lang, true);
        $result = [];

        foreach ($children as $child) {
            $category = new Category($child['id_category'], $this->id_lang);
            if (Validate::isLoadedObject($category) && $category->active) {
                $catData = $this->formatCategory($category, true);
                
                if ($withProducts) {
                    $catData['products'] = $this->getCategoryProducts($category->id, 8);
                }
                
                $result[] = $catData;
            }
        }

        return $result;
    }

    /**
     * Récupère quelques produits d'une catégorie
     */
    protected function getCategoryProducts(int $categoryId, int $limit = 8): array
    {
        $products = Product::getProducts(
            $this->id_lang,
            0,
            $limit,
            'date_add',
            'DESC',
            $categoryId,
            true
        );

        $result = [];
        foreach ($products as $productData) {
            $product = new Product($productData['id_product'], false, $this->id_lang);
            if (Validate::isLoadedObject($product)) {
                $result[] = $this->formatProduct($product);
            }
        }

        return $result;
    }
}
