<?php
/**
 * Contrôleur API CMS - Pages statiques (CGV, Mentions légales, etc.)
 */

class RavenapiCmsModuleFrontController extends ModuleFrontController
{
    public function init()
    {
        parent::init();
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
    }

    public function initContent()
    {
        parent::initContent();

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            die();
        }

        $action = Tools::getValue('action', 'list');
        
        switch ($action) {
            case 'get':
                $this->getCmsPage();
                break;
            case 'category':
                $this->getCmsCategory();
                break;
            case 'list':
            default:
                $this->getCmsList();
                break;
        }
    }

    /**
     * Liste toutes les pages CMS actives
     */
    private function getCmsList()
    {
        $id_lang = (int)Context::getContext()->language->id;
        $id_shop = (int)Context::getContext()->shop->id;
        
        $sql = "SELECT c.id_cms, cl.meta_title, cl.meta_description, cl.link_rewrite,
                       cc.id_cms_category, ccl.name as category_name
                FROM " . _DB_PREFIX_ . "cms c
                LEFT JOIN " . _DB_PREFIX_ . "cms_lang cl ON (c.id_cms = cl.id_cms AND cl.id_lang = " . $id_lang . ")
                LEFT JOIN " . _DB_PREFIX_ . "cms_shop cs ON (c.id_cms = cs.id_cms AND cs.id_shop = " . $id_shop . ")
                LEFT JOIN " . _DB_PREFIX_ . "cms_category cc ON (c.id_cms_category = cc.id_cms_category)
                LEFT JOIN " . _DB_PREFIX_ . "cms_category_lang ccl ON (cc.id_cms_category = ccl.id_cms_category AND ccl.id_lang = " . $id_lang . ")
                WHERE c.active = 1
                ORDER BY c.position ASC";
        
        $pages = Db::getInstance()->executeS($sql);
        
        $result = [];
        foreach ($pages as $page) {
            $result[] = [
                'id' => (int)$page['id_cms'],
                'title' => $page['meta_title'],
                'description' => $page['meta_description'],
                'slug' => $page['link_rewrite'],
                'category_id' => (int)$page['id_cms_category'],
                'category_name' => $page['category_name'],
                'url' => '/cms/' . $page['link_rewrite']
            ];
        }
        
        $this->jsonResponse([
            'success' => true,
            'pages' => $result,
            'total' => count($result)
        ]);
    }

    /**
     * Récupère une page CMS par ID ou slug
     */
    private function getCmsPage()
    {
        $id_cms = (int)Tools::getValue('id');
        $slug = Tools::getValue('slug', '');
        $id_lang = (int)Context::getContext()->language->id;
        
        if (!$id_cms && $slug) {
            // Rechercher par slug
            $sql = "SELECT c.id_cms FROM " . _DB_PREFIX_ . "cms c
                    LEFT JOIN " . _DB_PREFIX_ . "cms_lang cl ON (c.id_cms = cl.id_cms AND cl.id_lang = " . $id_lang . ")
                    WHERE cl.link_rewrite = '" . pSQL($slug) . "' AND c.active = 1";
            $result = Db::getInstance()->getRow($sql);
            $id_cms = $result ? (int)$result['id_cms'] : 0;
        }
        
        if (!$id_cms) {
            $this->jsonResponse(['error' => 'Page non trouvée'], 404);
            return;
        }
        
        $cms = new CMS($id_cms, $id_lang);
        
        if (!Validate::isLoadedObject($cms) || !$cms->active) {
            $this->jsonResponse(['error' => 'Page non trouvée'], 404);
            return;
        }
        
        // Récupérer la catégorie
        $category = new CMSCategory($cms->id_cms_category, $id_lang);
        
        $this->jsonResponse([
            'success' => true,
            'page' => [
                'id' => (int)$cms->id,
                'title' => $cms->meta_title,
                'content' => $cms->content,
                'description' => $cms->meta_description,
                'keywords' => $cms->meta_keywords,
                'slug' => $cms->link_rewrite,
                'category' => [
                    'id' => (int)$category->id,
                    'name' => $category->name
                ],
                'indexation' => (bool)$cms->indexation,
                'active' => (bool)$cms->active
            ]
        ]);
    }

    /**
     * Récupère les pages d'une catégorie CMS
     */
    private function getCmsCategory()
    {
        $id_category = (int)Tools::getValue('id', 1);
        $id_lang = (int)Context::getContext()->language->id;
        $id_shop = (int)Context::getContext()->shop->id;
        
        $category = new CMSCategory($id_category, $id_lang);
        
        if (!Validate::isLoadedObject($category) || !$category->active) {
            $this->jsonResponse(['error' => 'Catégorie non trouvée'], 404);
            return;
        }
        
        // Récupérer les pages de la catégorie
        $sql = "SELECT c.id_cms, cl.meta_title, cl.meta_description, cl.link_rewrite
                FROM " . _DB_PREFIX_ . "cms c
                LEFT JOIN " . _DB_PREFIX_ . "cms_lang cl ON (c.id_cms = cl.id_cms AND cl.id_lang = " . $id_lang . ")
                LEFT JOIN " . _DB_PREFIX_ . "cms_shop cs ON (c.id_cms = cs.id_cms AND cs.id_shop = " . $id_shop . ")
                WHERE c.id_cms_category = " . $id_category . " AND c.active = 1
                ORDER BY c.position ASC";
        
        $pages = Db::getInstance()->executeS($sql);
        
        $result = [];
        foreach ($pages as $page) {
            $result[] = [
                'id' => (int)$page['id_cms'],
                'title' => $page['meta_title'],
                'description' => $page['meta_description'],
                'slug' => $page['link_rewrite'],
                'url' => '/cms/' . $page['link_rewrite']
            ];
        }
        
        // Récupérer les sous-catégories
        $subcategories = CMSCategory::getChildren($id_category, $id_lang, true, $id_shop);
        
        $this->jsonResponse([
            'success' => true,
            'category' => [
                'id' => (int)$category->id,
                'name' => $category->name,
                'description' => $category->description
            ],
            'pages' => $result,
            'subcategories' => array_map(function($sub) {
                return [
                    'id' => (int)$sub['id_cms_category'],
                    'name' => $sub['name'],
                    'url' => '/cms/category/' . $sub['id_cms_category']
                ];
            }, $subcategories ?: [])
        ]);
    }

    private function jsonResponse($data, $code = 200)
    {
        http_response_code($code);
        die(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
