<?php
/**
 * API Category Controller
 * GET /api/v1/categories/{id}
 */

require_once _PS_MODULE_DIR_ . 'ravenapi/classes/RavenApiBaseController.php';

class RavenapiCategoryModuleFrontController extends RavenApiBaseModuleFrontController
{
    public function initContent()
    {
        parent::initContent();

        if ($this->getMethod() !== 'GET') {
            $this->sendError('Method not allowed', 405);
        }

        $this->getCategory();
    }

    protected function getCategory()
    {
        $idOrSlug = Tools::getValue('id');

        if (empty($idOrSlug)) {
            $this->sendError('Category ID or slug required', 400);
        }

        // Recherche par ID ou slug
        if (is_numeric($idOrSlug)) {
            $category = new Category((int) $idOrSlug, $this->id_lang);
        } else {
            // Recherche par link_rewrite
            $sql = 'SELECT c.id_category 
                    FROM ' . _DB_PREFIX_ . 'category c
                    INNER JOIN ' . _DB_PREFIX_ . 'category_lang cl ON c.id_category = cl.id_category
                    WHERE cl.link_rewrite = "' . pSQL($idOrSlug) . '" 
                    AND cl.id_lang = ' . (int) $this->id_lang . '
                    LIMIT 1';
            $categoryId = Db::getInstance()->getValue($sql);
            
            if (!$categoryId) {
                $this->sendError('Category not found', 404);
            }
            
            $category = new Category((int) $categoryId, $this->id_lang);
        }

        if (!Validate::isLoadedObject($category) || !$category->active) {
            $this->sendError('Category not found', 404);
        }

        $data = $this->formatCategory($category, true);
        
        // Breadcrumb
        $data['breadcrumb'] = $this->getBreadcrumb($category);

        // Filtres disponibles
        $data['available_filters'] = $this->getAvailableFilters($category->id);

        $this->sendResponse($data);
    }

    /**
     * Génère le breadcrumb
     */
    protected function getBreadcrumb(Category $category): array
    {
        $breadcrumb = [
            ['name' => 'Accueil', 'url' => '/'],
        ];

        $parents = $category->getParentsCategories($this->id_lang);
        $parents = array_reverse($parents);
        
        foreach ($parents as $parent) {
            if ((int) $parent['id_category'] > 2) {
                $breadcrumb[] = [
                    'name' => $parent['name'],
                    'url' => '/category/' . $parent['link_rewrite'],
                ];
            }
        }

        return $breadcrumb;
    }

    /**
     * Récupère les filtres disponibles pour une catégorie
     */
    protected function getAvailableFilters(int $categoryId): array
    {
        $filters = [];

        // Fabricants présents dans cette catégorie
        $sql = 'SELECT DISTINCT m.id_manufacturer, m.name
                FROM ' . _DB_PREFIX_ . 'product p
                INNER JOIN ' . _DB_PREFIX_ . 'category_product cp ON p.id_product = cp.id_product
                INNER JOIN ' . _DB_PREFIX_ . 'manufacturer m ON p.id_manufacturer = m.id_manufacturer
                WHERE cp.id_category = ' . (int) $categoryId . '
                AND m.active = 1
                ORDER BY m.name ASC';
        
        $manufacturers = Db::getInstance()->executeS($sql);
        $filters['manufacturers'] = array_map(function ($m) {
            return [
                'id' => (int) $m['id_manufacturer'],
                'name' => $m['name'],
            ];
        }, $manufacturers);

        // Plage de prix
        $sql = 'SELECT MIN(ps.price) as min_price, MAX(ps.price) as max_price
                FROM ' . _DB_PREFIX_ . 'product p
                INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps ON p.id_product = ps.id_product
                INNER JOIN ' . _DB_PREFIX_ . 'category_product cp ON p.id_product = cp.id_product
                WHERE cp.id_category = ' . (int) $categoryId . '
                AND ps.active = 1';
        
        $priceRange = Db::getInstance()->getRow($sql);
        $filters['price_range'] = [
            'min' => (float) ($priceRange['min_price'] ?? 0),
            'max' => (float) ($priceRange['max_price'] ?? 0),
        ];

        // Caractéristiques (features)
        $sql = 'SELECT DISTINCT f.id_feature, fl.name as feature_name, fv.id_feature_value, fvl.value
                FROM ' . _DB_PREFIX_ . 'feature_product fp
                INNER JOIN ' . _DB_PREFIX_ . 'product p ON fp.id_product = p.id_product
                INNER JOIN ' . _DB_PREFIX_ . 'category_product cp ON p.id_product = cp.id_product
                INNER JOIN ' . _DB_PREFIX_ . 'feature f ON fp.id_feature = f.id_feature
                INNER JOIN ' . _DB_PREFIX_ . 'feature_lang fl ON f.id_feature = fl.id_feature AND fl.id_lang = ' . (int) $this->id_lang . '
                INNER JOIN ' . _DB_PREFIX_ . 'feature_value fv ON fp.id_feature_value = fv.id_feature_value
                INNER JOIN ' . _DB_PREFIX_ . 'feature_value_lang fvl ON fv.id_feature_value = fvl.id_feature_value AND fvl.id_lang = ' . (int) $this->id_lang . '
                WHERE cp.id_category = ' . (int) $categoryId . '
                ORDER BY f.position, fl.name, fvl.value';
        
        $features = Db::getInstance()->executeS($sql);
        $groupedFeatures = [];
        
        foreach ($features as $feature) {
            $featureId = (int) $feature['id_feature'];
            if (!isset($groupedFeatures[$featureId])) {
                $groupedFeatures[$featureId] = [
                    'id' => $featureId,
                    'name' => $feature['feature_name'],
                    'values' => [],
                ];
            }
            $groupedFeatures[$featureId]['values'][] = [
                'id' => (int) $feature['id_feature_value'],
                'value' => $feature['value'],
            ];
        }
        
        $filters['features'] = array_values($groupedFeatures);

        return $filters;
    }
}
