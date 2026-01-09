<?php
/**
 * Newsletter API Controller for RavenAPI
 * Handles newsletter subscriptions
 */

class RavenapiNewsletterModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();
        
        $this->ajax = true;
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            die();
        }
        
        switch ($_SERVER['REQUEST_METHOD']) {
            case 'POST':
                $this->handleSubscribe();
                break;
            case 'GET':
                $this->handleCheck();
                break;
            default:
                $this->jsonResponse(['error' => 'Method not allowed'], 405);
        }
    }
    
    /**
     * Handle newsletter subscription
     */
    private function handleSubscribe()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $email = isset($input['email']) ? trim($input['email']) : '';
        
        if (!$email || !Validate::isEmail($email)) {
            $this->jsonResponse(['error' => 'Invalid email address'], 400);
            return;
        }
        
        // Check if email already exists
        $existing = Db::getInstance()->getRow(
            'SELECT * FROM ' . _DB_PREFIX_ . 'emailsubscription WHERE email = "' . pSQL($email) . '"'
        );
        
        if ($existing) {
            if ($existing['active']) {
                $this->jsonResponse([
                    'success' => true,
                    'message' => 'Vous êtes déjà inscrit à notre newsletter',
                    'already_subscribed' => true
                ]);
                return;
            } else {
                // Reactivate subscription
                Db::getInstance()->update('emailsubscription', [
                    'active' => 1,
                    'newsletter_date_add' => date('Y-m-d H:i:s')
                ], 'email = "' . pSQL($email) . '"');
                
                $this->jsonResponse([
                    'success' => true,
                    'message' => 'Votre inscription a été réactivée'
                ]);
                return;
            }
        }
        
        // Insert new subscription
        $result = Db::getInstance()->insert('emailsubscription', [
            'id_shop' => (int) Context::getContext()->shop->id,
            'id_shop_group' => (int) Context::getContext()->shop->id_shop_group,
            'email' => pSQL($email),
            'newsletter_date_add' => date('Y-m-d H:i:s'),
            'ip_registration_newsletter' => pSQL(Tools::getRemoteAddr()),
            'http_referer' => pSQL($_SERVER['HTTP_REFERER'] ?? ''),
            'active' => 1
        ]);
        
        if ($result) {
            // Send welcome email (optional)
            $this->sendWelcomeEmail($email);
            
            $this->jsonResponse([
                'success' => true,
                'message' => 'Merci pour votre inscription !',
                'discount_code' => 'BIENVENUE10'
            ]);
        } else {
            $this->jsonResponse(['error' => 'Subscription failed'], 500);
        }
    }
    
    /**
     * Check if email is subscribed
     */
    private function handleCheck()
    {
        $email = Tools::getValue('email');
        
        if (!$email || !Validate::isEmail($email)) {
            $this->jsonResponse(['error' => 'Invalid email'], 400);
            return;
        }
        
        $existing = Db::getInstance()->getRow(
            'SELECT * FROM ' . _DB_PREFIX_ . 'emailsubscription WHERE email = "' . pSQL($email) . '" AND active = 1'
        );
        
        $this->jsonResponse([
            'subscribed' => (bool) $existing
        ]);
    }
    
    /**
     * Send welcome email to new subscriber
     */
    private function sendWelcomeEmail($email)
    {
        try {
            $templateVars = [
                '{email}' => $email,
                '{discount_code}' => 'BIENVENUE10',
                '{discount_percent}' => '10',
                '{shop_name}' => Configuration::get('PS_SHOP_NAME'),
                '{shop_url}' => Context::getContext()->link->getBaseLink()
            ];
            
            Mail::Send(
                (int) Configuration::get('PS_LANG_DEFAULT'),
                'newsletter_welcome',
                'Bienvenue chez ' . Configuration::get('PS_SHOP_NAME') . ' - Votre code de réduction',
                $templateVars,
                $email,
                null,
                null,
                null,
                null,
                null,
                _PS_MODULE_DIR_ . 'ravenapi/mails/'
            );
        } catch (Exception $e) {
            // Log error but don't fail the subscription
            PrestaShopLogger::addLog('Newsletter welcome email failed: ' . $e->getMessage(), 3);
        }
    }
    
    /**
     * Send JSON response
     */
    private function jsonResponse($data, $code = 200)
    {
        http_response_code($code);
        die(json_encode($data));
    }
}
