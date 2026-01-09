<?php
/**
 * API Order Controller
 * Détail d'une commande
 */

require_once _PS_MODULE_DIR_ . 'ravenapi/classes/RavenApiBaseController.php';

class RavenapiOrderModuleFrontController extends RavenApiBaseModuleFrontController
{
    public function initContent()
    {
        parent::initContent();
        $this->requireAuth();

        if ($this->getMethod() !== 'GET') {
            $this->sendError('Method not allowed', 405);
        }

        $this->getOrder();
    }

    /**
     * GET - Détail d'une commande
     */
    protected function getOrder()
    {
        $orderId = (int) Tools::getValue('id');
        $customer = $this->getCustomer();

        if (!$orderId) {
            $this->sendError('Order ID required', 400);
        }

        $order = new Order($orderId, $this->id_lang);

        if (!Validate::isLoadedObject($order)) {
            $this->sendError('Order not found', 404);
        }

        // Vérifier que la commande appartient au client
        if ($order->id_customer != $customer->id) {
            $this->sendError('Order not found', 404);
        }

        $this->sendResponse($this->formatOrder($order, true));
    }
}
