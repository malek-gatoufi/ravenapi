<?php
/**
 * API Checkout Controller
 * Gère le tunnel de commande
 */

require_once _PS_MODULE_DIR_ . 'ravenapi/classes/RavenApiBaseController.php';

class RavenapiCheckoutModuleFrontController extends RavenApiBaseModuleFrontController
{
    public function initContent()
    {
        parent::initContent();

        $action = $this->getParam('action', 'info');

        switch ($this->getMethod()) {
            case 'GET':
                $this->getCheckoutInfo();
                break;
            case 'POST':
                $this->processCheckout();
                break;
            case 'PUT':
            case 'PATCH':
                $this->updateCheckoutStep();
                break;
            default:
                $this->sendError('Method not allowed', 405);
        }
    }

    /**
     * GET - Récupère les informations de checkout
     */
    protected function getCheckoutInfo()
    {
        $cart = $this->getCart();
        
        if (!$cart->id || !$cart->nbProducts()) {
            $this->sendError('Cart is empty', 400);
        }

        $customer = $this->getCustomer();
        $isLoggedIn = $customer !== null;

        $response = [
            'cart' => $this->formatCart($cart),
            'is_logged_in' => $isLoggedIn,
            'addresses' => [],
            'carriers' => [],
            'payment_methods' => [],
        ];

        if ($isLoggedIn) {
            // Adresses du client
            $addresses = $customer->getAddresses($this->id_lang);
            $response['addresses'] = array_map(function ($addr) {
                $address = new Address($addr['id_address'], $this->id_lang);
                return $this->formatAddress($address);
            }, $addresses);

            // Transporteurs disponibles
            if ($cart->id_address_delivery) {
                $carriers = $cart->simulateCarriersOutput(null, true);
                $response['carriers'] = array_map(function ($carrier) {
                    return [
                        'id' => (int) $carrier['id_carrier'],
                        'name' => $carrier['name'],
                        'delay' => $carrier['delay'],
                        'price' => round((float) $carrier['price'], 2),
                        'logo' => $carrier['logo'] ?? null,
                    ];
                }, $carriers);
            }

            // Méthodes de paiement
            $response['payment_methods'] = $this->getPaymentMethods();
        }

        $this->sendResponse($response);
    }

    /**
     * PUT/PATCH - Met à jour une étape du checkout
     */
    protected function updateCheckoutStep()
    {
        $this->requireAuth();
        
        $step = $this->getBodyParam('step');
        $cart = $this->getCart();
        
        // Debug log
        PrestaShopLogger::addLog('Checkout step received: ' . var_export($step, true) . ' | All body params: ' . json_encode($this->requestData), 1);
        
        if (empty($step)) {
            $this->sendError('Step parameter is required', 400);
        }

        switch ($step) {
            case 'address': // Frontend envoie 'address'
                // Mettre à jour les deux adresses en une fois
                $idAddressDelivery = (int) $this->getBodyParam('id_address_delivery');
                $idAddressInvoice = (int) $this->getBodyParam('id_address_invoice');
                
                if (!$idAddressDelivery) {
                    $this->sendError('Delivery address required', 400);
                }
                
                // Vérifier que les adresses appartiennent au client
                $customer = $this->getCustomer();
                $deliveryAddr = new Address($idAddressDelivery);
                if (!Validate::isLoadedObject($deliveryAddr) || $deliveryAddr->id_customer != $customer->id) {
                    $this->sendError('Invalid delivery address', 400);
                }
                
                if ($idAddressInvoice) {
                    $invoiceAddr = new Address($idAddressInvoice);
                    if (!Validate::isLoadedObject($invoiceAddr) || $invoiceAddr->id_customer != $customer->id) {
                        $this->sendError('Invalid invoice address', 400);
                    }
                }
                
                $cart->id_address_delivery = $idAddressDelivery;
                $cart->id_address_invoice = $idAddressInvoice ?: $idAddressDelivery;
                $cart->update();
                
                // Récupérer les transporteurs disponibles
                $carriers = $cart->simulateCarriersOutput(null, true);
                
                $this->sendResponse([
                    'success' => true,
                    'cart' => $this->formatCart($cart),
                    'carriers' => array_map(function ($carrier) {
                        return [
                            'id' => (int) $carrier['id_carrier'],
                            'name' => $carrier['name'],
                            'delay' => $carrier['delay'],
                            'price' => round((float) $carrier['price'], 2),
                            'logo' => $carrier['logo'] ?? null,
                        ];
                    }, $carriers),
                ]);
                break;
                
            case 'shipping': // Frontend envoie 'shipping'
                $idCarrier = (int) $this->getBodyParam('id_carrier');
                if (!$idCarrier) {
                    $this->sendError('Carrier required', 400);
                }
                
                $carrier = new Carrier($idCarrier, $this->id_lang);
                if (!Validate::isLoadedObject($carrier) || !$carrier->active) {
                    $this->sendError('Invalid carrier', 400);
                }
                
                $cart->id_carrier = $idCarrier;
                $cart->update();
                
                // Récupérer les méthodes de paiement
                $paymentMethods = $this->getPaymentMethods();
                
                $this->sendResponse([
                    'success' => true,
                    'cart' => $this->formatCart($cart),
                    'payment_options' => $paymentMethods,
                ]);
                break;
                
            case 'delivery_address':
                $this->updateDeliveryAddress($cart);
                break;
            case 'invoice_address':
                $this->updateInvoiceAddress($cart);
                break;
            case 'carrier':
                $this->updateCarrier($cart);
                break;
            default:
                $this->sendError('Invalid step', 400);
        }
    }

    /**
     * Met à jour l'adresse de livraison
     */
    protected function updateDeliveryAddress(Cart $cart)
    {
        $addressId = (int) $this->getBodyParam('id_address');
        
        if (!$addressId) {
            // Créer une nouvelle adresse
            $address = $this->createAddressFromRequest();
            $addressId = $address->id;
        } else {
            // Vérifier que l'adresse appartient au client
            $address = new Address($addressId);
            if (!Validate::isLoadedObject($address) || $address->id_customer != $this->getCustomer()->id) {
                $this->sendError('Invalid address', 400);
            }
        }

        $cart->id_address_delivery = $addressId;
        $cart->update();
        
        // Récupérer les transporteurs disponibles
        $carriers = $cart->simulateCarriersOutput(null, true);

        $this->sendResponse([
            'success' => true,
            'cart' => $this->formatCart($cart),
            'carriers' => array_map(function ($carrier) {
                return [
                    'id' => (int) $carrier['id_carrier'],
                    'name' => $carrier['name'],
                    'delay' => $carrier['delay'],
                    'price' => round((float) $carrier['price'], 2),
                ];
            }, $carriers),
        ]);
    }

    /**
     * Met à jour l'adresse de facturation
     */
    protected function updateInvoiceAddress(Cart $cart)
    {
        $addressId = (int) $this->getBodyParam('id_address');
        $sameAsDelivery = (bool) $this->getBodyParam('same_as_delivery', false);

        if ($sameAsDelivery) {
            $addressId = $cart->id_address_delivery;
        } elseif (!$addressId) {
            $address = $this->createAddressFromRequest();
            $addressId = $address->id;
        } else {
            $address = new Address($addressId);
            if (!Validate::isLoadedObject($address) || $address->id_customer != $this->getCustomer()->id) {
                $this->sendError('Invalid address', 400);
            }
        }

        $cart->id_address_invoice = $addressId;
        $cart->update();

        $this->sendResponse([
            'success' => true,
            'cart' => $this->formatCart($cart),
        ]);
    }

    /**
     * Met à jour le transporteur
     */
    protected function updateCarrier(Cart $cart)
    {
        $carrierId = (int) $this->getBodyParam('id_carrier');

        if (!$carrierId) {
            $this->sendError('Carrier ID required', 400);
        }

        $carrier = new Carrier($carrierId);
        if (!Validate::isLoadedObject($carrier) || !$carrier->active) {
            $this->sendError('Invalid carrier', 400);
        }

        $cart->id_carrier = $carrierId;
        $cart->setDeliveryOption([
            $cart->id_address_delivery => $carrierId . ',',
        ]);
        $cart->update();

        $this->sendResponse([
            'success' => true,
            'cart' => $this->formatCart($cart),
        ]);
    }

    /**
     * POST - Valide la commande
     */
    protected function processCheckout()
    {
        $this->requireAuth();

        $cart = $this->getCart();
        $customer = $this->getCustomer();

        // Validations
        if (!$cart->id || !$cart->nbProducts()) {
            $this->sendError('Cart is empty', 400);
        }

        if (!$cart->id_address_delivery) {
            $this->sendError('Delivery address required', 400);
        }

        if (!$cart->id_address_invoice) {
            $cart->id_address_invoice = $cart->id_address_delivery;
        }

        if (!$cart->id_carrier) {
            $this->sendError('Carrier required', 400);
        }

        $paymentMethod = $this->getBodyParam('payment_method');
        if (!$paymentMethod) {
            $this->sendError('Payment method required', 400);
        }

        // Vérifier le stock
        foreach ($cart->getProducts() as $product) {
            $stock = StockAvailable::getQuantityAvailableByProduct(
                $product['id_product'],
                $product['id_product_attribute']
            );
            $p = new Product($product['id_product']);
            if (!Product::isAvailableWhenOutOfStock($p->out_of_stock) && $stock < $product['quantity']) {
                $this->sendError('Insufficient stock for product: ' . $product['name'], 400);
            }
        }

        // Trouver le module de paiement
        $paymentModule = Module::getInstanceByName($paymentMethod);
        if (!$paymentModule || !$paymentModule->active) {
            $this->sendError('Invalid payment method', 400);
        }

        // Pour les paiements simples (type virement/chèque), créer la commande directement
        // Pour les paiements en ligne, retourner l'URL de paiement
        if (in_array($paymentMethod, ['ps_wirepayment', 'ps_checkpayment', 'paymentfree'])) {
            $order = $this->createOrder($cart, $customer, $paymentModule);
            
            // Vider le panier
            $cart->delete();
            Context::getContext()->cookie->id_cart = 0;

            $this->sendResponse([
                'success' => true,
                'order' => $this->formatOrder($order),
                'redirect_url' => null,
            ]);
        } else {
            // Pour les autres paiements, retourner l'URL
            $this->sendResponse([
                'success' => true,
                'order' => null,
                'redirect_url' => Context::getContext()->link->getModuleLink(
                    $paymentMethod,
                    'payment',
                    ['id_cart' => $cart->id]
                ),
            ]);
        }
    }

    /**
     * Crée une commande
     */
    protected function createOrder(Cart $cart, Customer $customer, Module $paymentModule): Order
    {
        $currency = new Currency($cart->id_currency);
        $carrier = new Carrier($cart->id_carrier);
        
        // État de la commande selon le moyen de paiement
        $orderState = (int) Configuration::get('PS_OS_BANKWIRE'); // En attente de paiement
        
        // Valider la commande
        $paymentModule->validateOrder(
            (int) $cart->id,
            $orderState,
            $cart->getOrderTotal(true),
            $paymentModule->displayName,
            null,
            [],
            (int) $currency->id,
            false,
            $customer->secure_key
        );

        return new Order($paymentModule->currentOrder);
    }

    /**
     * Crée une adresse depuis les données de la requête
     */
    protected function createAddressFromRequest(): Address
    {
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
        $errors = [];
        if (empty($address->address1)) $errors['address1'] = ['Address is required'];
        if (empty($address->postcode)) $errors['postcode'] = ['Postcode is required'];
        if (empty($address->city)) $errors['city'] = ['City is required'];

        if (!empty($errors)) {
            $this->sendError('Validation failed', 422, $errors);
        }

        if (!$address->add()) {
            $this->sendError('Failed to create address', 500);
        }

        return $address;
    }

    /**
     * Récupère les méthodes de paiement disponibles
     */
    protected function getPaymentMethods(): array
    {
        $methods = [];
        
        // Modules de paiement actifs
        $paymentModules = PaymentModule::getInstalledPaymentModules();
        
        foreach ($paymentModules as $module) {
            $moduleInstance = Module::getInstanceByName($module['name']);
            if ($moduleInstance && $moduleInstance->active) {
                $methods[] = [
                    'id' => $module['name'],
                    'name' => $moduleInstance->displayName,
                    'description' => $moduleInstance->description ?? '',
                    'logo' => file_exists(_PS_MODULE_DIR_ . $module['name'] . '/logo.png') 
                        ? _MODULE_DIR_ . $module['name'] . '/logo.png' 
                        : null,
                ];
            }
        }

        return $methods;
    }
}
