<?php
/**
 * API Auth Controller
 * Gère l'authentification
 */

require_once _PS_MODULE_DIR_ . 'ravenapi/classes/RavenApiBaseController.php';

class RavenapiAuthModuleFrontController extends RavenApiBaseModuleFrontController
{
    public function initContent()
    {
        parent::initContent();

        $action = Tools::getValue('action', 'status');

        switch ($action) {
            case 'login':
                $this->login();
                break;
            case 'register':
                $this->register();
                break;
            case 'logout':
                $this->logout();
                break;
            case 'status':
                $this->status();
                break;
            case 'forgot':
                $this->forgotPassword();
                break;
            case 'reset':
                $this->resetPassword();
                break;
            default:
                $this->sendError('Invalid action', 400);
        }
    }

    /**
     * GET /api/v1/auth/status - Vérifie si l'utilisateur est connecté
     */
    protected function status()
    {
        if ($this->getMethod() !== 'GET') {
            $this->sendError('Method not allowed', 405);
        }

        $customer = $this->getCustomer();
        
        if ($customer) {
            $this->sendResponse([
                'logged_in' => true,
                'customer' => $this->formatCustomer($customer),
            ]);
        } else {
            $this->sendResponse([
                'logged_in' => false,
                'customer' => null,
            ]);
        }
    }

    /**
     * POST /api/v1/auth/login - Connexion
     */
    protected function login()
    {
        if ($this->getMethod() !== 'POST') {
            $this->sendError('Method not allowed', 405);
        }

        $email = $this->getBodyParam('email');
        $password = $this->getBodyParam('password');

        if (empty($email) || empty($password)) {
            $this->sendError('Email and password required', 400);
        }

        if (!Validate::isEmail($email)) {
            $this->sendError('Invalid email format', 400);
        }

        // Vérifier le client
        $customer = new Customer();
        $authentication = $customer->getByEmail($email, $password);

        if (!$authentication || !$customer->id) {
            $this->sendError('Invalid credentials', 401);
        }

        if (!$customer->active) {
            $this->sendError('Account is disabled', 403);
        }

        // Connecter le client
        $context = Context::getContext();
        $context->customer = $customer;
        $context->cookie->id_customer = $customer->id;
        $context->cookie->customer_lastname = $customer->lastname;
        $context->cookie->customer_firstname = $customer->firstname;
        $context->cookie->logged = 1;
        $context->cookie->passwd = $customer->passwd;
        $context->cookie->email = $customer->email;
        $context->cookie->is_guest = 0;

        // Enregistrer la session (requis depuis PrestaShop 1.7.8)
        $context->cookie->registerSession(new CustomerSession());

        // Persister les cookies
        $context->cookie->write();

        // Mettre à jour le panier
        $cart = $this->getCart();
        if ($cart->id) {
            $cart->id_customer = $customer->id;
            $cart->id_address_delivery = Address::getFirstCustomerAddressId($customer->id);
            $cart->id_address_invoice = $cart->id_address_delivery;
            $cart->update();
        }

        // Récupérer le panier précédent si existant
        $oldCart = Cart::getCartByOrderId($customer->id);
        if ($oldCart) {
            // Fusionner les paniers si nécessaire
        }

        $this->sendResponse([
            'success' => true,
            'customer' => $this->formatCustomer($customer),
            'cart' => $this->formatCart($cart),
        ]);
    }

    /**
     * POST /api/v1/auth/register - Inscription
     */
    protected function register()
    {
        if ($this->getMethod() !== 'POST') {
            $this->sendError('Method not allowed', 405);
        }

        $email = $this->getBodyParam('email');
        $password = $this->getBodyParam('password');
        $firstname = $this->getBodyParam('firstname');
        $lastname = $this->getBodyParam('lastname');
        $birthday = $this->getBodyParam('birthday', null);
        $newsletter = (bool) $this->getBodyParam('newsletter', false);
        $idGender = (int) $this->getBodyParam('id_gender', 0);

        // Validation
        $errors = [];

        if (empty($email) || !Validate::isEmail($email)) {
            $errors['email'] = ['Invalid email address'];
        }

        if (Customer::customerExists($email)) {
            $errors['email'] = ['Email already registered'];
        }

        if (empty($password) || strlen($password) < 8) {
            $errors['password'] = ['Password must be at least 8 characters'];
        }

        if (empty($firstname) || !Validate::isName($firstname)) {
            $errors['firstname'] = ['Invalid first name'];
        }

        if (empty($lastname) || !Validate::isName($lastname)) {
            $errors['lastname'] = ['Invalid last name'];
        }

        if ($birthday && !Validate::isBirthDate($birthday)) {
            $errors['birthday'] = ['Invalid birth date'];
        }

        if (!empty($errors)) {
            $this->sendError('Validation failed', 422, $errors);
        }

        // Créer le client
        $customer = new Customer();
        $customer->email = $email;
        $customer->passwd = Tools::hash($password);
        $customer->firstname = $firstname;
        $customer->lastname = $lastname;
        $customer->birthday = $birthday;
        $customer->newsletter = $newsletter;
        $customer->optin = $newsletter;
        $customer->id_gender = $idGender;
        $customer->active = 1;
        $customer->is_guest = 0;
        $customer->id_shop = $this->id_shop;
        $customer->id_default_group = (int) Configuration::get('PS_CUSTOMER_GROUP');

        if (!$customer->add()) {
            $this->sendError('Failed to create account', 500);
        }

        // Groupes par défaut
        $customer->updateGroup([(int) Configuration::get('PS_CUSTOMER_GROUP')]);

        // Connecter automatiquement
        $context = Context::getContext();
        $context->customer = $customer;
        $context->cookie->id_customer = $customer->id;
        $context->cookie->customer_lastname = $customer->lastname;
        $context->cookie->customer_firstname = $customer->firstname;
        $context->cookie->logged = 1;
        $context->cookie->passwd = $customer->passwd;
        $context->cookie->email = $customer->email;
        $context->cookie->is_guest = 0;

        // Enregistrer la session (requis depuis PrestaShop 1.7.8)
        $context->cookie->registerSession(new CustomerSession());

        // Persister les cookies
        $context->cookie->write();

        // Mettre à jour le panier
        $cart = $this->getCart();
        if ($cart->id) {
            $cart->id_customer = $customer->id;
            $cart->update();
        }

        // Email de bienvenue
        if (Configuration::get('PS_CUSTOMER_CREATION_EMAIL')) {
            Mail::Send(
                $this->id_lang,
                'account',
                Mail::l('Welcome!'),
                [
                    '{firstname}' => $customer->firstname,
                    '{lastname}' => $customer->lastname,
                    '{email}' => $customer->email,
                ],
                $customer->email,
                $customer->firstname . ' ' . $customer->lastname
            );
        }

        $this->sendResponse([
            'success' => true,
            'customer' => $this->formatCustomer($customer),
            'cart' => $this->formatCart($cart),
        ]);
    }

    /**
     * POST /api/v1/auth/logout - Déconnexion
     */
    protected function logout()
    {
        if ($this->getMethod() !== 'POST') {
            $this->sendError('Method not allowed', 405);
        }

        $context = Context::getContext();
        
        // Déconnecter
        $context->customer->logout();
        
        // Nettoyer les cookies
        $context->cookie->logout();
        
        // Créer un nouveau panier vide
        $context->cart = new Cart();
        $context->cookie->id_cart = 0;

        $this->sendResponse([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * POST /api/v1/auth/forgot - Mot de passe oublié
     */
    protected function forgotPassword()
    {
        if ($this->getMethod() !== 'POST') {
            $this->sendError('Method not allowed', 405);
        }

        $email = $this->getBodyParam('email');

        if (empty($email) || !Validate::isEmail($email)) {
            $this->sendError('Invalid email', 400);
        }

        $customer = new Customer();
        $customer->getByEmail($email);

        if (!Validate::isLoadedObject($customer)) {
            // Ne pas révéler si l'email existe
            $this->sendResponse([
                'success' => true,
                'message' => 'If this email exists, you will receive a reset link',
            ]);
        }

        // Générer un token
        $token = md5(uniqid(mt_rand(), true));
        
        // Sauvegarder le token (PrestaShop le stocke dans reset_password_token)
        $customer->reset_password_token = $token;
        $customer->reset_password_validity = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $customer->update();

        // Envoyer l'email
        $resetUrl = Configuration::get('RAVEN_API_CORS_ORIGIN') . '/reset-password?token=' . $token . '&email=' . urlencode($email);
        
        Mail::Send(
            $this->id_lang,
            'password_query',
            Mail::l('Password reset'),
            [
                '{email}' => $customer->email,
                '{lastname}' => $customer->lastname,
                '{firstname}' => $customer->firstname,
                '{url}' => $resetUrl,
            ],
            $customer->email,
            $customer->firstname . ' ' . $customer->lastname
        );

        $this->sendResponse([
            'success' => true,
            'message' => 'If this email exists, you will receive a reset link',
        ]);
    }

    /**
     * POST /api/v1/auth/reset - Réinitialiser le mot de passe
     */
    protected function resetPassword()
    {
        if ($this->getMethod() !== 'POST') {
            $this->sendError('Method not allowed', 405);
        }

        $email = $this->getBodyParam('email');
        $token = $this->getBodyParam('token');
        $password = $this->getBodyParam('password');

        if (empty($email) || empty($token) || empty($password)) {
            $this->sendError('Email, token and password required', 400);
        }

        if (strlen($password) < 8) {
            $this->sendError('Password must be at least 8 characters', 400);
        }

        // Vérifier le token
        $customer = new Customer();
        $customer->getByEmail($email);

        if (!Validate::isLoadedObject($customer)) {
            $this->sendError('Invalid token', 400);
        }

        if ($customer->reset_password_token !== $token) {
            $this->sendError('Invalid token', 400);
        }

        if (strtotime($customer->reset_password_validity) < time()) {
            $this->sendError('Token expired', 400);
        }

        // Mettre à jour le mot de passe
        $customer->passwd = Tools::hash($password);
        $customer->reset_password_token = null;
        $customer->reset_password_validity = null;
        $customer->update();

        $this->sendResponse([
            'success' => true,
            'message' => 'Password reset successfully',
        ]);
    }
}
