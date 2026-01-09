<?php
/**
 * API Addresses Controller
 * Gestion des adresses client
 */

require_once _PS_MODULE_DIR_ . 'ravenapi/classes/RavenApiBaseController.php';

class RavenapiAddressesModuleFrontController extends RavenApiBaseModuleFrontController
{
    public function initContent()
    {
        parent::initContent();
        $this->requireAuth();

        switch ($this->getMethod()) {
            case 'GET':
                $this->getAddresses();
                break;
            case 'POST':
                $this->createAddress();
                break;
            case 'PUT':
            case 'PATCH':
                $this->updateAddress();
                break;
            case 'DELETE':
                $this->deleteAddress();
                break;
            default:
                $this->sendError('Method not allowed', 405);
        }
    }

    /**
     * GET - Liste des adresses
     */
    protected function getAddresses()
    {
        $customer = $this->getCustomer();
        $addresses = $customer->getAddresses($this->id_lang);
        
        $result = array_map(function ($addr) {
            $address = new Address($addr['id_address'], $this->id_lang);
            return $this->formatAddress($address);
        }, $addresses);

        // Ajouter la liste des pays
        $countries = Country::getCountries($this->id_lang, true);

        $this->sendResponse([
            'addresses' => $result,
            'countries' => array_map(function ($country) {
                return [
                    'id' => (int) $country['id_country'],
                    'name' => $country['name'],
                    'iso_code' => $country['iso_code'],
                    'need_zip_code' => (bool) $country['need_zip_code'],
                    'zip_code_format' => $country['zip_code_format'],
                ];
            }, $countries),
        ]);
    }

    /**
     * POST - Créer une adresse
     */
    protected function createAddress()
    {
        try {
            $customer = $this->getCustomer();
            
            $address = new Address();
            $address->id_customer = $customer->id;
            $address->alias = $this->getBodyParam('alias', 'Mon adresse');
            $address->firstname = $this->getBodyParam('firstname', $customer->firstname);
            $address->lastname = $this->getBodyParam('lastname', $customer->lastname);
            $address->company = $this->getBodyParam('company', '');
            $address->address1 = $this->getBodyParam('address1');
            $address->address2 = $this->getBodyParam('address2', '');
            $address->postcode = $this->getBodyParam('postcode');
            $address->city = $this->getBodyParam('city');
            $address->id_country = (int) $this->getBodyParam('id_country', Configuration::get('PS_COUNTRY_DEFAULT'));
            $address->id_state = (int) $this->getBodyParam('id_state', 0);
            $address->phone = $this->getBodyParam('phone', '');
            $address->phone_mobile = $this->getBodyParam('phone_mobile', '');

            // Validation
            $errors = $this->validateAddress($address);
            if (!empty($errors)) {
                $this->sendError('Validation failed', 422, $errors);
            }

            if (!$address->add()) {
                // Log l'erreur PrestaShop
                PrestaShopLogger::addLog('Failed to create address: ' . Db::getInstance()->getMsgError(), 3);
                $this->sendError('Failed to create address: ' . Db::getInstance()->getMsgError(), 500);
            }

            $this->sendResponse([
                'success' => true,
                'address' => $this->formatAddress($address),
            ]);
        } catch (Exception $e) {
            PrestaShopLogger::addLog('Address creation exception: ' . $e->getMessage(), 3);
            $this->sendError('Server error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT/PATCH - Modifier une adresse
     */
    protected function updateAddress()
    {
        $addressId = (int) $this->getBodyParam('id');
        $customer = $this->getCustomer();

        if (!$addressId) {
            $this->sendError('Address ID required', 400);
        }

        $address = new Address($addressId, $this->id_lang);
        
        if (!Validate::isLoadedObject($address) || $address->id_customer != $customer->id) {
            $this->sendError('Address not found', 404);
        }

        // Mise à jour des champs
        if ($this->getBodyParam('alias') !== null) {
            $address->alias = $this->getBodyParam('alias');
        }
        if ($this->getBodyParam('firstname') !== null) {
            $address->firstname = $this->getBodyParam('firstname');
        }
        if ($this->getBodyParam('lastname') !== null) {
            $address->lastname = $this->getBodyParam('lastname');
        }
        if ($this->getBodyParam('company') !== null) {
            $address->company = $this->getBodyParam('company');
        }
        if ($this->getBodyParam('address1') !== null) {
            $address->address1 = $this->getBodyParam('address1');
        }
        if ($this->getBodyParam('address2') !== null) {
            $address->address2 = $this->getBodyParam('address2');
        }
        if ($this->getBodyParam('postcode') !== null) {
            $address->postcode = $this->getBodyParam('postcode');
        }
        if ($this->getBodyParam('city') !== null) {
            $address->city = $this->getBodyParam('city');
        }
        if ($this->getBodyParam('id_country') !== null) {
            $address->id_country = (int) $this->getBodyParam('id_country');
        }
        if ($this->getBodyParam('id_state') !== null) {
            $address->id_state = (int) $this->getBodyParam('id_state');
        }
        if ($this->getBodyParam('phone') !== null) {
            $address->phone = $this->getBodyParam('phone');
        }
        if ($this->getBodyParam('phone_mobile') !== null) {
            $address->phone_mobile = $this->getBodyParam('phone_mobile');
        }

        // Validation
        $errors = $this->validateAddress($address);
        if (!empty($errors)) {
            $this->sendError('Validation failed', 422, $errors);
        }

        if (!$address->update()) {
            $this->sendError('Failed to update address', 500);
        }

        $this->sendResponse([
            'success' => true,
            'address' => $this->formatAddress($address),
        ]);
    }

    /**
     * DELETE - Supprimer une adresse
     */
    protected function deleteAddress()
    {
        $addressId = (int) $this->getBodyParam('id');
        $customer = $this->getCustomer();

        if (!$addressId) {
            $this->sendError('Address ID required', 400);
        }

        $address = new Address($addressId, $this->id_lang);
        
        if (!Validate::isLoadedObject($address) || $address->id_customer != $customer->id) {
            $this->sendError('Address not found', 404);
        }

        // Vérifier si l'adresse est utilisée par une commande
        $sql = 'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'orders 
                WHERE id_address_delivery = ' . (int) $addressId . ' 
                OR id_address_invoice = ' . (int) $addressId;
        
        if ((int) Db::getInstance()->getValue($sql) > 0) {
            // Marquer comme supprimée plutôt que de vraiment supprimer
            $address->deleted = 1;
            $address->update();
        } else {
            $address->delete();
        }

        $this->sendResponse([
            'success' => true,
            'message' => 'Address deleted',
        ]);
    }

    /**
     * Valide une adresse
     */
    protected function validateAddress(Address $address): array
    {
        $errors = [];

        if (empty($address->alias)) {
            $errors['alias'] = ['Alias is required'];
        }
        if (empty($address->firstname) || !Validate::isName($address->firstname)) {
            $errors['firstname'] = ['Invalid first name'];
        }
        if (empty($address->lastname) || !Validate::isName($address->lastname)) {
            $errors['lastname'] = ['Invalid last name'];
        }
        if (empty($address->address1)) {
            $errors['address1'] = ['Address is required'];
        }
        if (empty($address->city) || !Validate::isCityName($address->city)) {
            $errors['city'] = ['Invalid city'];
        }
        if (empty($address->postcode) || !Validate::isPostCode($address->postcode)) {
            $errors['postcode'] = ['Invalid postcode'];
        }
        if (!$address->id_country) {
            $errors['id_country'] = ['Country is required'];
        }

        // Vérifier le format du code postal selon le pays
        $country = new Country($address->id_country);
        if ($country->need_zip_code && !empty($country->zip_code_format)) {
            // Vérifier le format avec une regex basée sur le format du pays
            $zip_regexp = '/^' . str_replace(' ', '( |)', str_replace(array('N', 'L', 'C'), array('[0-9]', '[a-zA-Z]', $country->iso_code), $country->zip_code_format)) . '$/i';
            if (!preg_match($zip_regexp, $address->postcode)) {
                $errors['postcode'] = ['Invalid postcode format for this country'];
            }
        }

        // Vérifier l'état si requis
        if ($country->contains_states && !$address->id_state) {
            $errors['id_state'] = ['State is required for this country'];
        }

        return $errors;
    }
}
