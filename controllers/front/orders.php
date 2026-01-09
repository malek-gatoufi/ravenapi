<?php
/**
 * API Orders Controller
 * Liste des commandes client
 */

require_once _PS_MODULE_DIR_ . 'ravenapi/classes/RavenApiBaseController.php';

class RavenapiOrdersModuleFrontController extends RavenApiBaseModuleFrontController
{
    public function initContent()
    {
        parent::initContent();
        $this->requireAuth();

        if ($this->getMethod() !== 'GET') {
            $this->sendError('Method not allowed', 405);
        }

        $this->getOrders();
    }

    /**
     * GET - Liste des commandes
     */
    protected function getOrders()
    {
        $customer = $this->getCustomer();
        
        $page = max(1, (int) $this->getParam('page', 1));
        $perPage = min(50, max(1, (int) $this->getParam('per_page', 10)));
        $offset = ($page - 1) * $perPage;

        // Compte total
        $total = (int) Order::getCustomerNbOrders($customer->id);

        // Récupérer les commandes
        $sql = 'SELECT id_order 
                FROM ' . _DB_PREFIX_ . 'orders 
                WHERE id_customer = ' . (int) $customer->id . ' 
                ORDER BY date_add DESC 
                LIMIT ' . (int) $offset . ', ' . (int) $perPage;

        $orderIds = Db::getInstance()->executeS($sql);
        
        $orders = [];
        foreach ($orderIds as $row) {
            $order = new Order($row['id_order'], $this->id_lang);
            if (Validate::isLoadedObject($order)) {
                $orders[] = $this->formatOrder($order);
            }
        }

        $this->sendPaginatedResponse($orders, $total, $page, $perPage);
    }
}
