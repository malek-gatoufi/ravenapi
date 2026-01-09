<?php
/**
 * Raven API Module
 * API REST pour le frontend Next.js
 *
 * @author Raven Industries
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class RavenApi extends Module
{
    public function __construct()
    {
        $this->name = 'ravenapi';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'Raven Industries';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Raven API');
        $this->description = $this->l('API REST pour le frontend Next.js Raven Industries');
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('moduleRoutes')
            && Configuration::updateValue('RAVEN_API_ENABLED', true)
            && Configuration::updateValue('RAVEN_API_CORS_ORIGIN', 'https://new.ravenindustries.fr');
    }

    public function uninstall()
    {
        return parent::uninstall()
            && Configuration::deleteByName('RAVEN_API_ENABLED')
            && Configuration::deleteByName('RAVEN_API_CORS_ORIGIN');
    }

    /**
     * Enregistrement des routes API
     */
    public function hookModuleRoutes()
    {
        return [
            // Products
            'module-ravenapi-products' => [
                'rule' => 'api/v1/products',
                'keywords' => [],
                'controller' => 'products',
                'params' => [
                    'fc' => 'module',
                    'module' => 'ravenapi',
                ],
            ],
            'module-ravenapi-product' => [
                'rule' => 'api/v1/products/{id}',
                'keywords' => [
                    'id' => ['regexp' => '[0-9a-z-]+', 'param' => 'id'],
                ],
                'controller' => 'product',
                'params' => [
                    'fc' => 'module',
                    'module' => 'ravenapi',
                ],
            ],
            // Categories
            'module-ravenapi-categories' => [
                'rule' => 'api/v1/categories',
                'keywords' => [],
                'controller' => 'categories',
                'params' => [
                    'fc' => 'module',
                    'module' => 'ravenapi',
                ],
            ],
            'module-ravenapi-category' => [
                'rule' => 'api/v1/categories/{id}',
                'keywords' => [
                    'id' => ['regexp' => '[0-9a-z-]+', 'param' => 'id'],
                ],
                'controller' => 'category',
                'params' => [
                    'fc' => 'module',
                    'module' => 'ravenapi',
                ],
            ],
            // Cart
            'module-ravenapi-cart' => [
                'rule' => 'api/v1/cart',
                'keywords' => [],
                'controller' => 'cart',
                'params' => [
                    'fc' => 'module',
                    'module' => 'ravenapi',
                ],
            ],
            // Auth
            'module-ravenapi-auth' => [
                'rule' => 'api/v1/auth/{action}',
                'keywords' => [
                    'action' => ['regexp' => '[a-z]+', 'param' => 'action'],
                ],
                'controller' => 'auth',
                'params' => [
                    'fc' => 'module',
                    'module' => 'ravenapi',
                ],
            ],
            // Customer
            'module-ravenapi-customer' => [
                'rule' => 'api/v1/customer',
                'keywords' => [],
                'controller' => 'customer',
                'params' => [
                    'fc' => 'module',
                    'module' => 'ravenapi',
                ],
            ],
            'module-ravenapi-customer-orders' => [
                'rule' => 'api/v1/customer/orders',
                'keywords' => [],
                'controller' => 'orders',
                'params' => [
                    'fc' => 'module',
                    'module' => 'ravenapi',
                ],
            ],
            'module-ravenapi-customer-order' => [
                'rule' => 'api/v1/customer/orders/{id}',
                'keywords' => [
                    'id' => ['regexp' => '[0-9]+', 'param' => 'id'],
                ],
                'controller' => 'order',
                'params' => [
                    'fc' => 'module',
                    'module' => 'ravenapi',
                ],
            ],
            'module-ravenapi-customer-addresses' => [
                'rule' => 'api/v1/customer/addresses',
                'keywords' => [],
                'controller' => 'addresses',
                'params' => [
                    'fc' => 'module',
                    'module' => 'ravenapi',
                ],
            ],
            // Checkout
            'module-ravenapi-checkout' => [
                'rule' => 'api/v1/checkout',
                'keywords' => [],
                'controller' => 'checkout',
                'params' => [
                    'fc' => 'module',
                    'module' => 'ravenapi',
                ],
            ],
            // Search
            'module-ravenapi-search' => [
                'rule' => 'api/v1/search',
                'keywords' => [],
                'controller' => 'search',
                'params' => [
                    'fc' => 'module',
                    'module' => 'ravenapi',
                ],
            ],
            // Manufacturers (marques)
            'module-ravenapi-manufacturers' => [
                'rule' => 'api/v1/manufacturers',
                'keywords' => [],
                'controller' => 'manufacturers',
                'params' => [
                    'fc' => 'module',
                    'module' => 'ravenapi',
                ],
            ],
        ];
    }

    /**
     * Configuration du module
     */
    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitRavenApi')) {
            Configuration::updateValue('RAVEN_API_ENABLED', (bool) Tools::getValue('RAVEN_API_ENABLED'));
            Configuration::updateValue('RAVEN_API_CORS_ORIGIN', Tools::getValue('RAVEN_API_CORS_ORIGIN'));
            $output .= $this->displayConfirmation($this->l('Configuration sauvegardée'));
        }

        return $output . $this->renderForm();
    }

    protected function renderForm()
    {
        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Configuration Raven API'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->l('API activée'),
                        'name' => 'RAVEN_API_ENABLED',
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'active_on', 'value' => 1, 'label' => $this->l('Oui')],
                            ['id' => 'active_off', 'value' => 0, 'label' => $this->l('Non')],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('CORS Origin'),
                        'name' => 'RAVEN_API_CORS_ORIGIN',
                        'desc' => $this->l('Domaine autorisé pour les appels API (ex: https://new.ravenindustries.fr)'),
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Sauvegarder'),
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->submit_action = 'submitRavenApi';
        $helper->fields_value = [
            'RAVEN_API_ENABLED' => Configuration::get('RAVEN_API_ENABLED'),
            'RAVEN_API_CORS_ORIGIN' => Configuration::get('RAVEN_API_CORS_ORIGIN'),
        ];

        return $helper->generateForm([$fields_form]);
    }
}
