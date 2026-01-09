<?php
/**
 * API Customer Controller
 * Gère le profil client
 */

require_once _PS_MODULE_DIR_ . 'ravenapi/classes/RavenApiBaseController.php';

class RavenapiCustomerModuleFrontController extends RavenApiBaseModuleFrontController
{
    public function initContent()
    {
        parent::initContent();
        $this->requireAuth();

        switch ($this->getMethod()) {
            case 'GET':
                $this->getProfile();
                break;
            case 'PUT':
            case 'PATCH':
                $this->updateProfile();
                break;
            default:
                $this->sendError('Method not allowed', 405);
        }
    }

    /**
     * GET - Récupère le profil
     */
    protected function getProfile()
    {
        $customer = $this->getCustomer();
        
        $this->sendResponse([
            'customer' => $this->formatCustomer($customer),
            'addresses' => $this->getCustomerAddresses($customer),
            'stats' => $this->getCustomerStats($customer),
        ]);
    }

    /**
     * PUT/PATCH - Met à jour le profil
     */
    protected function updateProfile()
    {
        $customer = $this->getCustomer();

        // Champs modifiables
        if ($this->getBodyParam('firstname') !== null) {
            $firstname = $this->getBodyParam('firstname');
            if (!Validate::isName($firstname)) {
                $this->sendError('Invalid first name', 400);
            }
            $customer->firstname = $firstname;
        }

        if ($this->getBodyParam('lastname') !== null) {
            $lastname = $this->getBodyParam('lastname');
            if (!Validate::isName($lastname)) {
                $this->sendError('Invalid last name', 400);
            }
            $customer->lastname = $lastname;
        }

        if ($this->getBodyParam('email') !== null) {
            $email = $this->getBodyParam('email');
            if (!Validate::isEmail($email)) {
                $this->sendError('Invalid email', 400);
            }
            // Vérifier si l'email n'est pas déjà utilisé
            if ($email !== $customer->email && Customer::customerExists($email)) {
                $this->sendError('Email already in use', 400);
            }
            $customer->email = $email;
        }

        if ($this->getBodyParam('birthday') !== null) {
            $birthday = $this->getBodyParam('birthday');
            if ($birthday && !Validate::isBirthDate($birthday)) {
                $this->sendError('Invalid birth date', 400);
            }
            $customer->birthday = $birthday;
        }

        if ($this->getBodyParam('id_gender') !== null) {
            $customer->id_gender = (int) $this->getBodyParam('id_gender');
        }

        if ($this->getBodyParam('newsletter') !== null) {
            $customer->newsletter = (bool) $this->getBodyParam('newsletter');
        }

        // Changement de mot de passe
        if ($this->getBodyParam('new_password') !== null) {
            $currentPassword = $this->getBodyParam('current_password');
            $newPassword = $this->getBodyParam('new_password');

            if (empty($currentPassword)) {
                $this->sendError('Current password required', 400);
            }

            // Vérifier le mot de passe actuel
            if (!$customer->getByEmail($customer->email, $currentPassword)) {
                $this->sendError('Invalid current password', 400);
            }

            if (strlen($newPassword) < 8) {
                $this->sendError('New password must be at least 8 characters', 400);
            }

            $customer->passwd = Tools::hash($newPassword);
        }

        if (!$customer->update()) {
            $this->sendError('Failed to update profile', 500);
        }

        // Mettre à jour les cookies
        $context = Context::getContext();
        $context->cookie->customer_lastname = $customer->lastname;
        $context->cookie->customer_firstname = $customer->firstname;
        $context->cookie->email = $customer->email;
        $context->cookie->passwd = $customer->passwd;

        $this->sendResponse([
            'success' => true,
            'customer' => $this->formatCustomer($customer),
        ]);
    }

    /**
     * Récupère les adresses du client
     */
    protected function getCustomerAddresses(Customer $customer): array
    {
        $addresses = $customer->getAddresses($this->id_lang);
        
        return array_map(function ($addr) {
            $address = new Address($addr['id_address'], $this->id_lang);
            return $this->formatAddress($address);
        }, $addresses);
    }

    /**
     * Récupère les statistiques du client
     */
    protected function getCustomerStats(Customer $customer): array
    {
        // Nombre de commandes
        $ordersCount = Order::getCustomerNbOrders($customer->id);

        // Total dépensé
        $sql = 'SELECT SUM(total_paid) as total 
                FROM ' . _DB_PREFIX_ . 'orders 
                WHERE id_customer = ' . (int) $customer->id . ' 
                AND valid = 1';
        $totalSpent = (float) Db::getInstance()->getValue($sql);

        // Dernière commande
        $sql = 'SELECT id_order, date_add 
                FROM ' . _DB_PREFIX_ . 'orders 
                WHERE id_customer = ' . (int) $customer->id . ' 
                ORDER BY date_add DESC 
                LIMIT 1';
        $lastOrder = Db::getInstance()->getRow($sql);

        return [
            'orders_count' => (int) $ordersCount,
            'total_spent' => round($totalSpent, 2),
            'last_order_date' => $lastOrder ? $lastOrder['date_add'] : null,
            'member_since' => $customer->date_add,
        ];
    }
}
