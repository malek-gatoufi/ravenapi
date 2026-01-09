<?php
/**
 * API Vouchers Controller
 * Gestion des bons de réduction client
 */

require_once _PS_MODULE_DIR_ . 'ravenapi/classes/RavenApiBaseController.php';

class RavenapiVouchersModuleFrontController extends RavenApiBaseModuleFrontController
{
    public function initContent()
    {
        parent::initContent();
        $this->requireAuth();

        switch ($this->getMethod()) {
            case 'GET':
                $this->getVouchers();
                break;
            default:
                $this->sendError('Method not allowed', 405);
        }
    }

    /**
     * GET - Liste des bons de réduction du client
     */
    protected function getVouchers()
    {
        $customer = $this->getCustomer();
        
        $cartRules = CartRule::getCustomerCartRules(
            $this->id_lang,
            $customer->id,
            true,
            true,
            false
        );

        $vouchers = [];
        foreach ($cartRules as $cartRule) {
            $cr = new CartRule($cartRule['id_cart_rule'], $this->id_lang);
            
            // Calculer la valeur
            $value = '';
            if ($cr->reduction_percent > 0) {
                $value = $cr->reduction_percent . '%';
            } elseif ($cr->reduction_amount > 0) {
                $value = Tools::displayPrice($cr->reduction_amount);
            } elseif ($cr->free_shipping) {
                $value = 'Livraison gratuite';
            }

            // Minimum de commande
            $minimum = '0';
            if ($cr->minimum_amount > 0) {
                $minimum = Tools::displayPrice($cr->minimum_amount);
            }

            // Vérifier si expiré
            $dateEnd = strtotime($cr->date_to);
            $isExpired = $dateEnd < time();

            $vouchers[] = [
                'id' => (int) $cr->id,
                'code' => $cr->code,
                'name' => $cr->name,
                'description' => $cr->description,
                'value' => $value,
                'quantity' => (int) $cartRule['quantity_for_user'],
                'minimum' => $minimum,
                'cumulative' => (bool) $cr->cart_rule_restriction,
                'expiration_date' => $cr->date_to,
                'is_expired' => $isExpired,
            ];
        }

        $this->sendResponse([
            'vouchers' => $vouchers,
        ]);
    }
}
