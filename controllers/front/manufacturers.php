<?php
/**
 * API Manufacturers Controller
 * Liste des marques
 */

require_once _PS_MODULE_DIR_ . 'ravenapi/classes/RavenApiBaseController.php';

class RavenapiManufacturersModuleFrontController extends RavenApiBaseModuleFrontController
{
    public function initContent()
    {
        parent::initContent();

        if ($this->getMethod() !== 'GET') {
            $this->sendError('Method not allowed', 405);
        }

        $id = Tools::getValue('id');
        if ($id) {
            $this->getManufacturer($id);
        } else {
            $this->getManufacturers();
        }
    }

    /**
     * GET - Liste des fabricants
     */
    protected function getManufacturers()
    {
        $page = max(1, (int) $this->getParam('page', 1));
        $perPage = min(100, max(1, (int) $this->getParam('per_page', 50)));
        $offset = ($page - 1) * $perPage;
        $withProductsOnly = $this->getParam('with_products', 'true') === 'true';

        // Construction de la requête
        $sql = new DbQuery();
        $sql->select('m.id_manufacturer, m.name, m.date_add');
        $sql->from('manufacturer', 'm');
        $sql->innerJoin('manufacturer_shop', 'ms', 'm.id_manufacturer = ms.id_manufacturer AND ms.id_shop = ' . (int) $this->id_shop);
        $sql->where('m.active = 1');

        if ($withProductsOnly) {
            $sql->innerJoin('product', 'p', 'm.id_manufacturer = p.id_manufacturer');
            $sql->innerJoin('product_shop', 'ps', 'p.id_product = ps.id_product AND ps.id_shop = ' . (int) $this->id_shop . ' AND ps.active = 1');
        }

        $sql->groupBy('m.id_manufacturer');
        $sql->orderBy('m.name ASC');

        // Compte total
        $sqlCount = clone $sql;
        $sqlCount->select('COUNT(DISTINCT m.id_manufacturer) as total', true);
        $total = (int) Db::getInstance()->getValue($sqlCount);

        $sql->limit($perPage, $offset);
        $manufacturers = Db::getInstance()->executeS($sql);

        $result = [];
        foreach ($manufacturers as $manu) {
            $manufacturer = new Manufacturer($manu['id_manufacturer'], $this->id_lang);
            $result[] = $this->formatManufacturer($manufacturer);
        }

        $this->sendPaginatedResponse($result, $total, $page, $perPage);
    }

    /**
     * GET - Détail d'un fabricant
     */
    protected function getManufacturer($idOrSlug)
    {
        if (is_numeric($idOrSlug)) {
            $manufacturer = new Manufacturer((int) $idOrSlug, $this->id_lang);
        } else {
            // Recherche par nom (slug approximatif)
            $sql = 'SELECT id_manufacturer FROM ' . _DB_PREFIX_ . 'manufacturer 
                    WHERE LOWER(REPLACE(name, " ", "-")) = "' . pSQL(strtolower($idOrSlug)) . '"
                    OR name = "' . pSQL($idOrSlug) . '"
                    LIMIT 1';
            $id = Db::getInstance()->getValue($sql);
            $manufacturer = new Manufacturer((int) $id, $this->id_lang);
        }

        if (!Validate::isLoadedObject($manufacturer) || !$manufacturer->active) {
            $this->sendError('Manufacturer not found', 404);
        }

        $data = $this->formatManufacturer($manufacturer, true);
        
        // Produits du fabricant
        $page = max(1, (int) $this->getParam('page', 1));
        $perPage = min(50, max(1, (int) $this->getParam('per_page', 20)));
        $offset = ($page - 1) * $perPage;

        $products = $manufacturer->getProductsLite($this->id_lang);
        $total = count($products);
        
        $productIds = array_slice($products, $offset, $perPage);
        $data['products'] = [
            'data' => array_map(function ($p) {
                $product = new Product($p['id_product'], false, $this->id_lang);
                return $this->formatProduct($product);
            }, $productIds),
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => ceil($total / $perPage),
            ],
        ];

        $this->sendResponse($data);
    }

    /**
     * Formate un fabricant pour l'API
     */
    protected function formatManufacturer(Manufacturer $manufacturer, bool $full = false): array
    {
        $link = Context::getContext()->link;
        
        $data = [
            'id' => (int) $manufacturer->id,
            'name' => $manufacturer->name,
            'logo' => file_exists(_PS_MANU_IMG_DIR_ . $manufacturer->id . '.jpg') 
                ? _THEME_MANU_DIR_ . $manufacturer->id . '.jpg'
                : null,
            'products_count' => (int) $manufacturer->getProductsLite($this->id_lang, 0, 0, null, null, null, true),
        ];

        if ($full) {
            $data['description'] = $manufacturer->description[$this->id_lang] ?? '';
            $data['short_description'] = $manufacturer->short_description[$this->id_lang] ?? '';
            $data['meta_title'] = $manufacturer->meta_title[$this->id_lang] ?? '';
            $data['meta_description'] = $manufacturer->meta_description[$this->id_lang] ?? '';
        }

        return $data;
    }
}
