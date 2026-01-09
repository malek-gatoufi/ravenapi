<?php
/**
 * API Payment Controller
 * Gère les moyens de paiement disponibles et initie les paiements
 */

require_once _PS_MODULE_DIR_ . 'ravenapi/classes/RavenApiBaseController.php';

class RavenapiPaymentModuleFrontController extends RavenApiBaseModuleFrontController
{
    public function initContent()
    {
        parent::initContent();

        $action = $this->getParam('action', 'methods');

        switch ($action) {
            case 'methods':
                $this->getPaymentMethodsList();
                break;
            case 'init':
                $this->initPayment();
                break;
            case 'callback':
                $this->handleCallback();
                break;
            case 'status':
                $this->checkPaymentStatus();
                break;
            default:
                $this->sendError('Invalid action', 400);
        }
    }

    /**
     * Liste les méthodes de paiement disponibles
     */
    protected function getPaymentMethodsList()
    {
        $cart = $this->getCart();
        
        if (!$cart->id || !$cart->nbProducts()) {
            $this->sendError('Cart is empty', 400);
        }

        $paymentModules = [];
        
        // PayPal
        if (Module::isInstalled('paypal') && Module::isEnabled('paypal')) {
            $paypal = Module::getInstanceByName('paypal');
            if ($paypal && $paypal->active) {
                $paymentModules[] = [
                    'id' => 'paypal',
                    'name' => 'PayPal',
                    'type' => 'redirect',
                    'icon' => 'https://www.paypalobjects.com/webstatic/icon/pp258.png',
                    'description' => 'Payez en toute sécurité avec PayPal',
                ];
            }
        }
        
        // Virement bancaire
        if (Module::isInstalled('ps_wirepayment') && Module::isEnabled('ps_wirepayment')) {
            $wire = Module::getInstanceByName('ps_wirepayment');
            if ($wire && $wire->active) {
                $paymentModules[] = [
                    'id' => 'ps_wirepayment',
                    'name' => 'Virement bancaire',
                    'type' => 'offline',
                    'icon' => null,
                    'description' => 'Paiement par virement bancaire',
                    'details' => [
                        'bank' => Configuration::get('BANK_WIRE_OWNER'),
                        'iban' => Configuration::get('BANK_WIRE_DETAILS'),
                    ],
                ];
            }
        }
        
        // Chèque
        if (Module::isInstalled('ps_checkpayment') && Module::isEnabled('ps_checkpayment')) {
            $check = Module::getInstanceByName('ps_checkpayment');
            if ($check && $check->active) {
                $paymentModules[] = [
                    'id' => 'ps_checkpayment',
                    'name' => 'Chèque',
                    'type' => 'offline',
                    'icon' => null,
                    'description' => 'Paiement par chèque',
                    'details' => [
                        'order_to' => Configuration::get('CHEQUE_NAME'),
                        'send_to' => Configuration::get('CHEQUE_ADDRESS'),
                    ],
                ];
            }
        }

        $this->sendResponse([
            'success' => true,
            'payment_methods' => $paymentModules,
            'cart_total' => round($cart->getOrderTotal(true), 2),
            'currency' => Context::getContext()->currency->iso_code,
        ]);
    }

    /**
     * Initialise un paiement
     */
    protected function initPayment()
    {
        $this->requireAuth();
        
        if ($this->getMethod() !== 'POST') {
            $this->sendError('Method not allowed', 405);
        }

        $cart = $this->getCart();
        $customer = $this->getCustomer();
        $paymentMethod = $this->getBodyParam('payment_method');

        if (!$cart->id || !$cart->nbProducts()) {
            $this->sendError('Cart is empty', 400);
        }

        if (!$cart->id_address_delivery || !$cart->id_carrier) {
            $this->sendError('Please complete shipping information first', 400);
        }

        switch ($paymentMethod) {
            case 'paypal':
                $this->initPayPal($cart, $customer);
                break;
            case 'ps_wirepayment':
            case 'ps_checkpayment':
                $this->initOfflinePayment($cart, $customer, $paymentMethod);
                break;
            default:
                $this->sendError('Invalid payment method', 400);
        }
    }

    /**
     * Initialise PayPal
     */
    protected function initPayPal(Cart $cart, Customer $customer)
    {
        // Vérifier que PayPal est actif
        $paypal = Module::getInstanceByName('paypal');
        if (!$paypal || !$paypal->active) {
            $this->sendError('PayPal is not available', 400);
        }

        // Générer l'URL de paiement PayPal
        $link = Context::getContext()->link;
        
        // URL vers le controller PayPal ecInit qui initie le paiement
        $paypalUrl = $link->getModuleLink('paypal', 'ecInit', [
            'id_cart' => $cart->id,
            'source' => 'api', // Pour identifier que ça vient de notre API
        ]);

        // URLs de retour
        $returnUrl = Configuration::get('RAVEN_API_FRONTEND_URL') ?: 'https://new.ravenindustries.fr';
        
        $this->sendResponse([
            'success' => true,
            'payment_method' => 'paypal',
            'redirect_url' => $paypalUrl,
            'return_urls' => [
                'success' => $returnUrl . '/confirmation?source=paypal',
                'cancel' => $returnUrl . '/panier?payment=cancelled',
                'error' => $returnUrl . '/panier?payment=error',
            ],
        ]);
    }

    /**
     * Initialise un paiement offline (virement, chèque)
     */
    protected function initOfflinePayment(Cart $cart, Customer $customer, string $moduleName)
    {
        $module = Module::getInstanceByName($moduleName);
        if (!$module || !$module->active) {
            $this->sendError('Payment method not available', 400);
        }

        $currency = new Currency($cart->id_currency);
        
        // Définir l'état de commande
        $orderState = $moduleName === 'ps_wirepayment' 
            ? (int) Configuration::get('PS_OS_BANKWIRE')
            : (int) Configuration::get('PS_OS_CHEQUE');

        try {
            // Valider la commande
            $module->validateOrder(
                (int) $cart->id,
                $orderState,
                $cart->getOrderTotal(true),
                $module->displayName,
                null,
                [],
                (int) $currency->id,
                false,
                $customer->secure_key
            );

            $orderId = (int) Order::getOrderByCartId($cart->id);
            $order = new Order($orderId);

            // Vider le panier
            Context::getContext()->cookie->id_cart = 0;

            $response = [
                'success' => true,
                'payment_method' => $moduleName,
                'order' => [
                    'id' => $order->id,
                    'reference' => $order->reference,
                    'total' => round($order->total_paid, 2),
                    'status' => $order->getCurrentStateFull($this->id_lang)['name'],
                ],
            ];

            // Ajouter les détails de paiement
            if ($moduleName === 'ps_wirepayment') {
                $response['payment_details'] = [
                    'type' => 'bank_transfer',
                    'owner' => Configuration::get('BANK_WIRE_OWNER'),
                    'bank_details' => Configuration::get('BANK_WIRE_DETAILS'),
                    'address' => Configuration::get('BANK_WIRE_ADDRESS'),
                ];
            } else {
                $response['payment_details'] = [
                    'type' => 'check',
                    'order_to' => Configuration::get('CHEQUE_NAME'),
                    'send_to' => Configuration::get('CHEQUE_ADDRESS'),
                ];
            }

            $this->sendResponse($response);

        } catch (Exception $e) {
            PrestaShopLogger::addLog('Payment Error: ' . $e->getMessage(), 3);
            $this->sendError('Order creation failed', 500);
        }
    }

    /**
     * Vérifie le statut d'un paiement
     */
    protected function checkPaymentStatus()
    {
        $orderId = (int) $this->getParam('order_id');
        $orderReference = $this->getParam('reference');

        if ($orderId) {
            $order = new Order($orderId);
        } elseif ($orderReference) {
            $orderId = (int) Order::getIdByReference($orderReference);
            $order = new Order($orderId);
        } else {
            $this->sendError('Order ID or reference required', 400);
        }

        if (!Validate::isLoadedObject($order)) {
            $this->sendError('Order not found', 404);
        }

        // Vérifier que la commande appartient au client
        $customer = $this->getCustomer();
        if ($customer && $order->id_customer != $customer->id) {
            $this->sendError('Access denied', 403);
        }

        $orderState = new OrderState($order->current_state, $this->id_lang);

        $this->sendResponse([
            'success' => true,
            'order' => [
                'id' => $order->id,
                'reference' => $order->reference,
                'total' => round($order->total_paid, 2),
                'status' => $orderState->name,
                'is_paid' => $order->hasBeenPaid(),
                'is_shipped' => $order->hasBeenShipped(),
                'is_delivered' => $order->hasBeenDelivered(),
            ],
        ]);
    }

    /**
     * Handle payment callback (webhook)
     */
    protected function handleCallback()
    {
        // Les webhooks PayPal sont gérés par le module PayPal natif
        // Ce endpoint est pour d'éventuelles notifications personnalisées
        $this->sendResponse([
            'success' => true,
            'message' => 'Callback received',
        ]);
    }
}
