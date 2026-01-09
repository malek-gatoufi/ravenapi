<?php
/**
 * API Search Controller
 * Recherche de produits
 */

require_once _PS_MODULE_DIR_ . 'ravenapi/classes/RavenApiBaseController.php';

class RavenapiSearchModuleFrontController extends RavenApiBaseModuleFrontController
{
    public function initContent()
    {
        parent::initContent();

        if ($this->getMethod() !== 'GET') {
            $this->sendError('Method not allowed', 405);
        }

        $this->search();
    }

    /**
     * GET - Recherche
     */
    protected function search()
    {
        $query = $this->getParam('q', '');
        $page = max(1, (int) $this->getParam('page', 1));
        $perPage = min(100, max(1, (int) $this->getParam('per_page', 20)));
        
        if (empty($query) || strlen($query) < 2) {
            $this->sendError('Search query must be at least 2 characters', 400);
        }

        // Recherche PrestaShop native
        $results = Search::find(
            $this->id_lang,
            $query,
            $page,
            $perPage,
            'position',
            'desc',
            true,
            false,
            Context::getContext()
        );

        $products = [];
        if (!empty($results['result'])) {
            foreach ($results['result'] as $productData) {
                $product = new Product($productData['id_product'], false, $this->id_lang);
                if (Validate::isLoadedObject($product) && $product->active) {
                    $products[] = $this->formatProduct($product);
                }
            }
        }

        $total = (int) ($results['total'] ?? count($products));

        // Suggestions de recherche
        $suggestions = $this->getSuggestions($query);

        $this->sendResponse([
            'data' => $products,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => ceil($total / $perPage),
            ],
            'query' => $query,
            'suggestions' => $suggestions,
        ]);
    }

    /**
     * Génère des suggestions de recherche
     */
    protected function getSuggestions(string $query): array
    {
        $suggestions = [];

        // Catégories correspondantes
        $sql = 'SELECT c.id_category, cl.name, cl.link_rewrite
                FROM ' . _DB_PREFIX_ . 'category c
                INNER JOIN ' . _DB_PREFIX_ . 'category_lang cl ON c.id_category = cl.id_category
                INNER JOIN ' . _DB_PREFIX_ . 'category_shop cs ON c.id_category = cs.id_category
                WHERE cl.id_lang = ' . (int) $this->id_lang . '
                AND cs.id_shop = ' . (int) $this->id_shop . '
                AND c.active = 1
                AND cl.name LIKE "%' . pSQL($query) . '%"
                ORDER BY c.level_depth, cl.name
                LIMIT 5';
        
        $categories = Db::getInstance()->executeS($sql);
        if ($categories) {
            $suggestions['categories'] = array_map(function ($cat) {
                return [
                    'id' => (int) $cat['id_category'],
                    'name' => $cat['name'],
                    'link_rewrite' => $cat['link_rewrite'],
                    'type' => 'category',
                ];
            }, $categories);
        }

        // Fabricants correspondants
        $sql = 'SELECT m.id_manufacturer, m.name
                FROM ' . _DB_PREFIX_ . 'manufacturer m
                INNER JOIN ' . _DB_PREFIX_ . 'manufacturer_shop ms ON m.id_manufacturer = ms.id_manufacturer
                WHERE ms.id_shop = ' . (int) $this->id_shop . '
                AND m.active = 1
                AND m.name LIKE "%' . pSQL($query) . '%"
                ORDER BY m.name
                LIMIT 5';
        
        $manufacturers = Db::getInstance()->executeS($sql);
        if ($manufacturers) {
            $suggestions['manufacturers'] = array_map(function ($manu) {
                return [
                    'id' => (int) $manu['id_manufacturer'],
                    'name' => $manu['name'],
                    'type' => 'manufacturer',
                ];
            }, $manufacturers);
        }

        // Références de produits correspondantes
        $sql = 'SELECT p.id_product, p.reference, pl.name, pl.link_rewrite
                FROM ' . _DB_PREFIX_ . 'product p
                INNER JOIN ' . _DB_PREFIX_ . 'product_lang pl ON p.id_product = pl.id_product
                INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps ON p.id_product = ps.id_product
                WHERE pl.id_lang = ' . (int) $this->id_lang . '
                AND ps.id_shop = ' . (int) $this->id_shop . '
                AND ps.active = 1
                AND p.reference LIKE "%' . pSQL($query) . '%"
                ORDER BY p.reference
                LIMIT 5';
        
        $references = Db::getInstance()->executeS($sql);
        if ($references) {
            $suggestions['references'] = array_map(function ($ref) {
                return [
                    'id' => (int) $ref['id_product'],
                    'reference' => $ref['reference'],
                    'name' => $ref['name'],
                    'link_rewrite' => $ref['link_rewrite'],
                    'type' => 'product',
                ];
            }, $references);
        }

        return $suggestions;
    }
}
