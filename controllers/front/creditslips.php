<?php
/**
 * API Credit Slips Controller
 * Gestion des avoirs client
 */

require_once _PS_MODULE_DIR_ . 'ravenapi/classes/RavenApiBaseController.php';

class RavenapiCreditslipsModuleFrontController extends RavenApiBaseModuleFrontController
{
    public function initContent()
    {
        parent::initContent();
        $this->requireAuth();

        switch ($this->getMethod()) {
            case 'GET':
                $this->getCreditSlips();
                break;
            default:
                $this->sendError('Method not allowed', 405);
        }
    }

    /**
     * GET - Liste des avoirs du client
     */
    protected function getCreditSlips()
    {
        $customer = $this->getCustomer();
        
        // Récupérer les avoirs du client
        $sql = 'SELECT os.*, o.reference as order_reference
                FROM ' . _DB_PREFIX_ . 'order_slip os
                LEFT JOIN ' . _DB_PREFIX_ . 'orders o ON (os.id_order = o.id_order)
                WHERE os.id_customer = ' . (int) $customer->id . '
                ORDER BY os.date_add DESC';
        
        $orderSlips = Db::getInstance()->executeS($sql);
        
        $creditSlips = [];
        if ($orderSlips) {
            foreach ($orderSlips as $slip) {
                // Calculer le montant total de l'avoir
                $amount = (float) $slip['total_products_tax_incl'] + (float) $slip['total_shipping_tax_incl'];
                
                // URL du PDF
                $pdfUrl = $this->context->link->getPageLink(
                    'pdf-order-slip',
                    true,
                    null,
                    ['id_order_slip' => (int) $slip['id_order_slip']]
                );

                $creditSlips[] = [
                    'id' => (int) $slip['id_order_slip'],
                    'reference' => sprintf('AV%06d', $slip['id_order_slip']),
                    'order_reference' => $slip['order_reference'],
                    'amount' => $amount,
                    'date_add' => $slip['date_add'],
                    'pdf_url' => $pdfUrl,
                ];
            }
        }

        $this->sendResponse([
            'credit_slips' => $creditSlips,
        ]);
    }
}
