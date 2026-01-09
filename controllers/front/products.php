<?php
/**
 * API Products Controller
 * GET /api/v1/products
 */

require_once _PS_MODULE_DIR_ . 'ravenapi/classes/RavenApiBaseController.php';

class RavenapiProductsModuleFrontController extends RavenApiBaseModuleFrontController
{
    public function initContent()
    {
        parent::initContent();

        if ($this->getMethod() !== 'GET') {
            $this->sendError('Method not allowed', 405);
        }

        $this->getProducts();
    }

    protected function getProducts()
    {
        // Paramètres de pagination
        $page = max(1, (int) $this->getParam('page', 1));
        $perPage = min(100, max(1, (int) $this->getParam('per_page', 20)));
        $offset = ($page - 1) * $perPage;

        // Paramètres de filtrage
        $categoryId = (int) $this->getParam('category', 0);
        $manufacturerId = (int) $this->getParam('manufacturer', 0);
        $search = $this->getParam('q', '');
        $priceMin = (float) $this->getParam('price_min', 0);
        $priceMax = (float) $this->getParam('price_max', 0);
        $onSale = $this->getParam('on_sale', null);
        $inStock = $this->getParam('in_stock', null);
        
        // Tri
        $orderBy = $this->getParam('order_by', 'date_add');
        $orderWay = strtoupper($this->getParam('order_way', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        // Construction de la requête
        $sql = new DbQuery();
        $sql->select('p.id_product');
        $sql->from('product', 'p');
        $sql->innerJoin('product_shop', 'ps', 'p.id_product = ps.id_product AND ps.id_shop = ' . (int) $this->id_shop);
        $sql->innerJoin('product_lang', 'pl', 'p.id_product = pl.id_product AND pl.id_lang = ' . (int) $this->id_lang . ' AND pl.id_shop = ' . (int) $this->id_shop);
        $sql->leftJoin('stock_available', 'sa', 'p.id_product = sa.id_product AND sa.id_product_attribute = 0 AND sa.id_shop = ' . (int) $this->id_shop);
        
        $sql->where('ps.active = 1');
        $sql->where('ps.visibility IN ("both", "catalog")');

        // Filtre catégorie
        if ($categoryId > 0) {
            $sql->innerJoin('category_product', 'cp', 'p.id_product = cp.id_product');
            $sql->where('cp.id_category = ' . (int) $categoryId);
        }

        // Filtre fabricant
        if ($manufacturerId > 0) {
            $sql->where('p.id_manufacturer = ' . (int) $manufacturerId);
        }

        // Filtre recherche
        if (!empty($search)) {
            $searchEscaped = pSQL($search);
            $sql->where("(pl.name LIKE '%{$searchEscaped}%' OR pl.description_short LIKE '%{$searchEscaped}%' OR p.reference LIKE '%{$searchEscaped}%')");
        }

        // Filtre promo
        if ($onSale !== null) {
            $sql->where('ps.on_sale = ' . ($onSale === 'true' || $onSale === '1' ? 1 : 0));
        }

        // Filtre stock
        if ($inStock !== null && ($inStock === 'true' || $inStock === '1')) {
            $sql->where('sa.quantity > 0');
        }

        // Compte total
        $sqlCount = clone $sql;
        $sqlCount->select('COUNT(DISTINCT p.id_product) as total', true);
        $total = (int) Db::getInstance()->getValue($sqlCount);

        // Tri
        $validOrderBy = ['date_add', 'price', 'name', 'quantity', 'position'];
        $orderBy = in_array($orderBy, $validOrderBy) ? $orderBy : 'date_add';
        
        switch ($orderBy) {
            case 'price':
                $sql->orderBy('ps.price ' . $orderWay);
                break;
            case 'name':
                $sql->orderBy('pl.name ' . $orderWay);
                break;
            case 'quantity':
                $sql->orderBy('sa.quantity ' . $orderWay);
                break;
            case 'position':
                if ($categoryId > 0) {
                    $sql->orderBy('cp.position ' . $orderWay);
                } else {
                    $sql->orderBy('p.date_add ' . $orderWay);
                }
                break;
            default:
                $sql->orderBy('p.date_add ' . $orderWay);
        }

        $sql->groupBy('p.id_product');
        $sql->limit($perPage, $offset);

        $productIds = Db::getInstance()->executeS($sql);
        
        $products = [];
        foreach ($productIds as $row) {
            $product = new Product($row['id_product'], true, $this->id_lang);
            if (Validate::isLoadedObject($product)) {
                $products[] = $this->formatProduct($product);
            }
        }

        // Filtrage prix (après récupération car le prix final dépend des règles)
        if ($priceMin > 0 || $priceMax > 0) {
            $products = array_filter($products, function ($p) use ($priceMin, $priceMax) {
                if ($priceMin > 0 && $p['price'] < $priceMin) {
                    return false;
                }
                if ($priceMax > 0 && $p['price'] > $priceMax) {
                    return false;
                }
                return true;
            });
            $products = array_values($products);
        }

        $this->sendPaginatedResponse($products, $total, $page, $perPage);
    }
}
