<?php
/**
 * API Returns Controller
 * Gestion des retours produits client
 */

require_once _PS_MODULE_DIR_ . 'ravenapi/classes/RavenApiBaseController.php';

class RavenapiReturnsModuleFrontController extends RavenApiBaseModuleFrontController
{
    public function initContent()
    {
        parent::initContent();
        $this->requireAuth();

        switch ($this->getMethod()) {
            case 'GET':
                $this->getReturns();
                break;
            default:
                $this->sendError('Method not allowed', 405);
        }
    }

    /**
     * GET - Liste des retours du client
     */
    protected function getReturns()
    {
        $customer = $this->getCustomer();
        
        // Récupérer les retours du client
        $sql = 'SELECT or.*, osl.name as state_name, o.reference as order_reference
                FROM ' . _DB_PREFIX_ . 'order_return or
                LEFT JOIN ' . _DB_PREFIX_ . 'order_return_state_lang osl 
                    ON (or.state = osl.id_order_return_state AND osl.id_lang = ' . (int) $this->id_lang . ')
                LEFT JOIN ' . _DB_PREFIX_ . 'orders o ON (or.id_order = o.id_order)
                WHERE or.id_customer = ' . (int) $customer->id . '
                ORDER BY or.date_add DESC';
        
        $ordersReturn = Db::getInstance()->executeS($sql);
        
        $returns = [];
        if ($ordersReturn) {
            foreach ($ordersReturn as $return) {
                // Récupérer les produits du retour
                $products = $this->getReturnProducts((int) $return['id_order_return']);
                
                // URL du bon de retour si disponible
                $printUrl = null;
                if ((int) $return['state'] >= 2) {
                    $printUrl = $this->context->link->getPageLink(
                        'pdf-order-return',
                        true,
                        null,
                        ['id_order_return' => (int) $return['id_order_return']]
                    );
                }

                $returns[] = [
                    'id' => (int) $return['id_order_return'],
                    'order_reference' => $return['order_reference'],
                    'return_number' => sprintf('%06d', $return['id_order_return']),
                    'state' => (int) $return['state'],
                    'state_name' => $return['state_name'],
                    'date_add' => $return['date_add'],
                    'details_url' => $this->context->link->getPageLink(
                        'order-detail',
                        true,
                        null,
                        ['id_order' => (int) $return['id_order']]
                    ),
                    'return_url' => $this->context->link->getPageLink(
                        'order-return',
                        true,
                        null,
                        ['id_order_return' => (int) $return['id_order_return']]
                    ),
                    'print_url' => $printUrl,
                    'products' => $products,
                ];
            }
        }

        $this->sendResponse([
            'returns' => $returns,
        ]);
    }

    /**
     * Récupérer les produits d'un retour
     */
    protected function getReturnProducts($idOrderReturn)
    {
        $sql = 'SELECT ord.*, pl.name, orr.name as reason
                FROM ' . _DB_PREFIX_ . 'order_return_detail ord
                LEFT JOIN ' . _DB_PREFIX_ . 'order_detail od ON (ord.id_order_detail = od.id_order_detail)
                LEFT JOIN ' . _DB_PREFIX_ . 'product_lang pl 
                    ON (od.product_id = pl.id_product AND pl.id_lang = ' . (int) $this->id_lang . ')
                LEFT JOIN ' . _DB_PREFIX_ . 'order_return_reason_lang orr
                    ON (ord.id_order_return_reason = orr.id_order_return_reason AND orr.id_lang = ' . (int) $this->id_lang . ')
                WHERE ord.id_order_return = ' . (int) $idOrderReturn;
        
        $details = Db::getInstance()->executeS($sql);
        
        $products = [];
        if ($details) {
            foreach ($details as $detail) {
                $products[] = [
                    'name' => $detail['name'],
                    'quantity' => (int) $detail['product_quantity'],
                    'reason' => $detail['reason'] ?: '',
                ];
            }
        }
        
        return $products;
    }
}
