<?php
/**
 * Contrôleur API Contact - Envoi de messages
 */

class RavenapiContactModuleFrontController extends ModuleFrontController
{
    public function init()
    {
        parent::init();
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
    }

    public function initContent()
    {
        parent::initContent();

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            die();
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['error' => 'Method not allowed'], 405);
            return;
        }

        $this->handleContactForm();
    }

    private function handleContactForm()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Validation
        $errors = [];
        
        $name = isset($input['name']) ? trim($input['name']) : '';
        $email = isset($input['email']) ? trim($input['email']) : '';
        $subject = isset($input['subject']) ? trim($input['subject']) : '';
        $message = isset($input['message']) ? trim($input['message']) : '';
        $id_contact = isset($input['id_contact']) ? (int)$input['id_contact'] : 1;
        
        if (empty($name)) {
            $errors['name'] = 'Le nom est requis';
        }
        
        if (empty($email) || !Validate::isEmail($email)) {
            $errors['email'] = 'Email invalide';
        }
        
        if (empty($subject)) {
            $errors['subject'] = 'Le sujet est requis';
        }
        
        if (empty($message) || strlen($message) < 10) {
            $errors['message'] = 'Le message doit faire au moins 10 caractères';
        }
        
        if (!empty($errors)) {
            $this->jsonResponse([
                'success' => false,
                'errors' => $errors
            ], 400);
            return;
        }
        
        // Créer le message dans PrestaShop
        try {
            $customer = Context::getContext()->customer;
            $id_customer = $customer && $customer->id ? $customer->id : 0;
            
            // Créer un thread de contact
            $ct = new CustomerThread();
            $ct->id_contact = $id_contact;
            $ct->id_customer = $id_customer;
            $ct->id_shop = (int)Context::getContext()->shop->id;
            $ct->id_lang = (int)Context::getContext()->language->id;
            $ct->email = $email;
            $ct->status = 'open';
            $ct->token = Tools::passwdGen(12);
            $ct->add();
            
            // Ajouter le message
            $cm = new CustomerMessage();
            $cm->id_customer_thread = $ct->id;
            $cm->message = $message;
            $cm->ip_address = ip2long(Tools::getRemoteAddr());
            $cm->user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $cm->add();
            
            // Envoyer un email de notification
            $contact = new Contact($id_contact, Context::getContext()->language->id);
            $contact_email = $contact->email ?: Configuration::get('PS_SHOP_EMAIL');
            
            Mail::Send(
                Context::getContext()->language->id,
                'contact',
                $subject,
                [
                    '{email}' => $email,
                    '{message}' => nl2br($message),
                    '{firstname}' => $name,
                    '{lastname}' => '',
                ],
                $contact_email,
                null,
                $email,
                $name
            );
            
            $this->jsonResponse([
                'success' => true,
                'message' => 'Votre message a bien été envoyé. Nous vous répondrons dans les plus brefs délais.'
            ]);
            
        } catch (Exception $e) {
            $this->jsonResponse([
                'success' => false,
                'error' => 'Erreur lors de l\'envoi du message'
            ], 500);
        }
    }

    private function jsonResponse($data, $code = 200)
    {
        http_response_code($code);
        die(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
