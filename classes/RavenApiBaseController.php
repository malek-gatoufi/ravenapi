<?php
/**
 * Base API Controller
 * Classe abstraite pour tous les controllers API
 */

abstract class RavenApiBaseModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ajax = true;

    /** @var array */
    protected $requestData = [];

    /** @var int */
    protected $id_lang;

    /** @var int */
    protected $id_shop;

    /**
     * Initialisation
     */
    public function init()
    {
        parent::init();

        $this->id_lang = (int) Context::getContext()->language->id;
        $this->id_shop = (int) Context::getContext()->shop->id;

        // Parse JSON body
        $input = file_get_contents('php://input');
        if (!empty($input)) {
            $this->requestData = json_decode($input, true) ?: [];
        }

        // Set CORS headers
        $this->setCorsHeaders();

        // Handle preflight
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            $this->sendResponse([]);
        }

        // Check if API is enabled
        if (!Configuration::get('RAVEN_API_ENABLED')) {
            $this->sendError('API is disabled', 503);
        }
    }

    /**
     * Set CORS headers
     */
    protected function setCorsHeaders()
    {
        // Accepter les origines autorisées
        $allowedOrigins = [
            'https://ravenindustries.fr',
            'https://www.ravenindustries.fr',
            'https://new.ravenindustries.fr',
            'http://localhost:3000',
        ];
        
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
        
        if (in_array($origin, $allowedOrigins)) {
            header('Access-Control-Allow-Origin: ' . $origin);
        } else {
            // Fallback pour le domaine principal
            header('Access-Control-Allow-Origin: https://ravenindustries.fr');
        }
        
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400');
    }

    /**
     * Envoie une réponse JSON
     */
    protected function sendResponse($data, int $statusCode = 200)
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Envoie une erreur JSON
     */
    protected function sendError(string $message, int $code = 400, array $errors = [])
    {
        $this->sendResponse([
            'error' => true,
            'code' => $code,
            'message' => $message,
            'errors' => $errors,
        ], $code);
    }

    /**
     * Envoie une réponse paginée
     */
    protected function sendPaginatedResponse(array $items, int $total, int $page, int $perPage)
    {
        $this->sendResponse([
            'data' => $items,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => ceil($total / $perPage),
            ],
        ]);
    }

    /**
     * Récupère un paramètre GET
     */
    protected function getParam(string $key, $default = null)
    {
        return Tools::getValue($key, $default);
    }

    /**
     * Récupère un paramètre du body JSON
     */
    protected function getBodyParam(string $key, $default = null)
    {
        return $this->requestData[$key] ?? $default;
    }

    /**
     * Récupère la méthode HTTP
     */
    protected function getMethod(): string
    {
        return $_SERVER['REQUEST_METHOD'];
    }

    /**
     * Vérifie si l'utilisateur est connecté
     */
    protected function isCustomerLoggedIn(): bool
    {
        return (bool) Context::getContext()->customer->isLogged();
    }

    /**
     * Récupère le client connecté
     */
    protected function getCustomer(): ?Customer
    {
        $context = Context::getContext();
        if ($context->customer && $context->customer->isLogged()) {
            return $context->customer;
        }
        return null;
    }

    /**
     * Vérifie que l'utilisateur est connecté
     */
    protected function requireAuth()
    {
        if (!$this->isCustomerLoggedIn()) {
            $this->sendError('Authentication required', 401);
        }
    }

    /**
     * Récupère le panier courant ou en crée un
     */
    protected function getCart(): Cart
    {
        $context = Context::getContext();
        
        if (!$context->cart || !$context->cart->id) {
            $cart = new Cart();
            $cart->id_lang = $this->id_lang;
            $cart->id_currency = $context->currency->id;
            $cart->id_shop = $this->id_shop;
            
            if ($context->customer && $context->customer->id) {
                $cart->id_customer = $context->customer->id;
                $cart->id_address_delivery = Address::getFirstCustomerAddressId($context->customer->id);
                $cart->id_address_invoice = $cart->id_address_delivery;
            } else {
                $cart->id_customer = 0;
                $cart->id_guest = $context->cookie->id_guest;
            }
            
            $cart->add();
            $context->cart = $cart;
            $context->cookie->id_cart = $cart->id;
        }
        
        return $context->cart;
    }

    /**
     * Génère l'URL d'une image produit
     */
    protected function getProductImageUrl(int $idProduct, int $idImage, string $imageType = 'home_default'): string
    {
        $link = Context::getContext()->link;
        $product = new Product($idProduct, false, $this->id_lang);
        $linkRewrite = is_array($product->link_rewrite) 
            ? ($product->link_rewrite[$this->id_lang] ?? reset($product->link_rewrite))
            : $product->link_rewrite;
        return $link->getImageLink($linkRewrite, $idImage, $imageType);
    }

    /**
     * Génère l'URL d'une image catégorie
     */
    protected function getCategoryImageUrl(int $idCategory, string $imageType = 'category_default'): string
    {
        $link = Context::getContext()->link;
        $category = new Category($idCategory, $this->id_lang);
        $linkRewrite = is_array($category->link_rewrite)
            ? ($category->link_rewrite[$this->id_lang] ?? reset($category->link_rewrite))
            : $category->link_rewrite;
        return $link->getCatImageLink($linkRewrite, $idCategory, $imageType);
    }

    /**
     * Formate un produit pour l'API
     */
    protected function formatProduct(Product $product, bool $full = false): array
    {
        $cover = Product::getCover($product->id);
        $coverUrl = $cover ? $this->getProductImageUrl($product->id, $cover['id_image']) : null;

        $priceWithReduction = $product->getPrice(true, null, 6);
        $priceWithoutReduction = $product->getPrice(true, null, 6, null, false, false);
        $reduction = $priceWithoutReduction - $priceWithReduction;
        $reductionPercent = $priceWithoutReduction > 0 
            ? round(($reduction / $priceWithoutReduction) * 100) 
            : 0;

        $data = [
            'id' => (int) $product->id,
            'name' => $product->name[$this->id_lang] ?? $product->name,
            'description_short' => strip_tags($product->description_short[$this->id_lang] ?? ''),
            'link_rewrite' => $product->link_rewrite[$this->id_lang] ?? $product->link_rewrite,
            'reference' => $product->reference,
            'price' => round($priceWithReduction, 2),
            'price_without_reduction' => round($priceWithoutReduction, 2),
            'reduction' => round($reduction, 2),
            'reduction_percent' => (int) $reductionPercent,
            'quantity' => StockAvailable::getQuantityAvailableByProduct($product->id),
            'active' => (bool) $product->active,
            'available_for_order' => (bool) $product->available_for_order,
            'on_sale' => (bool) $product->on_sale,
            'cover_image' => $coverUrl,
            'id_category_default' => (int) $product->id_category_default,
            'manufacturer_name' => Manufacturer::getNameById($product->id_manufacturer),
        ];

        if ($full) {
            $data['description'] = $product->description[$this->id_lang] ?? '';
            $data['meta_title'] = $product->meta_title[$this->id_lang] ?? '';
            $data['meta_description'] = $product->meta_description[$this->id_lang] ?? '';
            $data['ean13'] = $product->ean13;
            $data['upc'] = $product->upc;
            $data['weight'] = (float) $product->weight;
            $data['id_manufacturer'] = (int) $product->id_manufacturer;
            $data['condition'] = $product->condition;
            
            // Images
            $images = Image::getImages($this->id_lang, $product->id);
            $data['images'] = array_map(function ($img) use ($product) {
                return [
                    'id' => (int) $img['id_image'],
                    'url' => $this->getProductImageUrl($product->id, $img['id_image']),
                    'url_large' => $this->getProductImageUrl($product->id, $img['id_image'], 'large_default'),
                    'legend' => $img['legend'],
                ];
            }, $images);

            // Attributs (déclinaisons)
            $combinations = $product->getAttributeCombinations($this->id_lang);
            if (!empty($combinations)) {
                $data['combinations'] = $this->formatCombinations($product, $combinations);
            }

            // Features
            $features = $product->getFrontFeatures($this->id_lang);
            $data['features'] = array_map(function ($feature) {
                return [
                    'name' => $feature['name'],
                    'value' => $feature['value'],
                ];
            }, $features);

            // Catégories
            $categories = $product->getCategories();
            $data['categories'] = array_map(function ($catId) {
                $cat = new Category($catId, $this->id_lang);
                return [
                    'id' => (int) $cat->id,
                    'name' => $cat->name,
                    'link_rewrite' => $cat->link_rewrite,
                ];
            }, $categories);
        }

        return $data;
    }

    /**
     * Formate les combinaisons d'un produit
     */
    protected function formatCombinations(Product $product, array $combinations): array
    {
        $result = [];
        $grouped = [];

        foreach ($combinations as $combination) {
            $idAttr = $combination['id_product_attribute'];
            if (!isset($grouped[$idAttr])) {
                $grouped[$idAttr] = [
                    'id' => (int) $idAttr,
                    'reference' => $combination['reference'],
                    'quantity' => (int) $combination['quantity'],
                    'price' => round($product->getPrice(true, $idAttr), 2),
                    'attributes' => [],
                ];
            }
            $grouped[$idAttr]['attributes'][] = [
                'group' => $combination['group_name'],
                'name' => $combination['attribute_name'],
                'id_attribute_group' => (int) $combination['id_attribute_group'],
                'id_attribute' => (int) $combination['id_attribute'],
            ];
        }

        return array_values($grouped);
    }

    /**
     * Formate une catégorie pour l'API
     */
    protected function formatCategory(Category $category, bool $withChildren = false): array
    {
        $data = [
            'id' => (int) $category->id,
            'name' => $category->name,
            'description' => strip_tags($category->description ?? ''),
            'link_rewrite' => $category->link_rewrite,
            'id_parent' => (int) $category->id_parent,
            'level_depth' => (int) $category->level_depth,
            'active' => (bool) $category->active,
            'image_url' => $this->getCategoryImageUrl($category->id),
            'products_count' => $category->getProducts($this->id_lang, 0, 0, null, null, true),
        ];

        if ($withChildren) {
            $children = Category::getChildren($category->id, $this->id_lang, true);
            $data['children'] = array_map(function ($child) {
                $cat = new Category($child['id_category'], $this->id_lang);
                return $this->formatCategory($cat, false);
            }, $children);
        }

        return $data;
    }

    /**
     * Formate le panier pour l'API
     */
    protected function formatCart(Cart $cart): array
    {
        $products = $cart->getProducts(true);
        $items = [];

        foreach ($products as $product) {
            // Get image URL - try multiple sources
            $imageUrl = null;
            $idImage = $product['id_image'] ?? null;
            
            // If id_image is in format "id_product-id_image", extract the id_image part
            if ($idImage && strpos($idImage, '-') !== false) {
                $parts = explode('-', $idImage);
                $idImage = end($parts);
            }
            
            // If still no image, get cover image
            if (!$idImage) {
                $cover = Image::getCover($product['id_product']);
                $idImage = $cover ? $cover['id_image'] : null;
            }
            
            if ($idImage) {
                $imageUrl = $this->getProductImageUrl((int)$product['id_product'], (int)$idImage, 'cart_default');
            }
            
            $items[] = [
                'id_product' => (int) $product['id_product'],
                'id_product_attribute' => (int) $product['id_product_attribute'],
                'name' => $product['name'],
                'reference' => $product['reference'],
                'quantity' => (int) $product['quantity'],
                'price' => round((float) $product['price_wt'], 2),
                'total' => round((float) $product['total_wt'], 2),
                'image_url' => $imageUrl,
                'link_rewrite' => $product['link_rewrite'],
                'attributes' => $product['attributes'] ?? '',
            ];
        }

        $totals = $cart->getSummaryDetails();

        return [
            'id' => (int) $cart->id,
            'items' => $items,
            'total_products' => round((float) $totals['total_products_wt'], 2),
            'total_shipping' => round((float) $totals['total_shipping'], 2),
            'total_discounts' => round((float) $totals['total_discounts'], 2),
            'total' => round((float) $totals['total_price'], 2),
            'cart_rules' => array_map(function ($rule) {
                return [
                    'id' => (int) $rule['id_cart_rule'],
                    'name' => $rule['name'],
                    'code' => $rule['code'] ?? '',
                    'reduction' => round((float) $rule['value_real'], 2),
                ];
            }, $totals['cart_rules'] ?? []),
        ];
    }

    /**
     * Formate un client pour l'API
     */
    protected function formatCustomer(Customer $customer): array
    {
        return [
            'id' => (int) $customer->id,
            'email' => $customer->email,
            'firstname' => $customer->firstname,
            'lastname' => $customer->lastname,
            'birthday' => $customer->birthday,
            'newsletter' => (bool) $customer->newsletter,
            'optin' => (bool) $customer->optin,
            'id_gender' => (int) $customer->id_gender,
            'date_add' => $customer->date_add,
        ];
    }

    /**
     * Formate une adresse pour l'API
     */
    protected function formatAddress(Address $address): array
    {
        return [
            'id' => (int) $address->id,
            'alias' => $address->alias,
            'firstname' => $address->firstname,
            'lastname' => $address->lastname,
            'company' => $address->company,
            'address1' => $address->address1,
            'address2' => $address->address2,
            'postcode' => $address->postcode,
            'city' => $address->city,
            'id_country' => (int) $address->id_country,
            'country' => Country::getNameById($this->id_lang, $address->id_country),
            'id_state' => (int) $address->id_state,
            'phone' => $address->phone,
            'phone_mobile' => $address->phone_mobile,
        ];
    }

    /**
     * Formate une commande pour l'API
     */
    protected function formatOrder(Order $order, bool $full = false): array
    {
        $data = [
            'id' => (int) $order->id,
            'reference' => $order->reference,
            'date_add' => $order->date_add,
            'total_paid' => round((float) $order->total_paid, 2),
            'total_products' => round((float) $order->total_products_wt, 2),
            'total_shipping' => round((float) $order->total_shipping, 2),
            'payment' => $order->payment,
            'current_state' => (int) $order->current_state,
            'state_name' => $this->getOrderStateName($order->current_state),
        ];

        if ($full) {
            // Produits de la commande
            $products = $order->getProducts();
            $data['products'] = array_map(function ($product) {
                return [
                    'id_product' => (int) $product['id_product'],
                    'name' => $product['product_name'],
                    'reference' => $product['product_reference'],
                    'quantity' => (int) $product['product_quantity'],
                    'price' => round((float) $product['unit_price_tax_incl'], 2),
                    'total' => round((float) $product['total_price_tax_incl'], 2),
                ];
            }, $products);

            // Adresses
            $deliveryAddress = new Address($order->id_address_delivery, $this->id_lang);
            $invoiceAddress = new Address($order->id_address_invoice, $this->id_lang);
            
            $data['delivery_address'] = $this->formatAddress($deliveryAddress);
            $data['invoice_address'] = $this->formatAddress($invoiceAddress);

            // Historique
            $history = $order->getHistory($this->id_lang);
            $data['history'] = array_map(function ($state) {
                return [
                    'id_order_state' => (int) $state['id_order_state'],
                    'name' => $state['ostate_name'],
                    'date_add' => $state['date_add'],
                ];
            }, $history);

            // Carrier
            $carrier = new Carrier($order->id_carrier, $this->id_lang);
            $data['carrier'] = [
                'id' => (int) $carrier->id,
                'name' => $carrier->name,
            ];
        }

        return $data;
    }

    /**
     * Récupère le nom d'un état de commande
     */
    protected function getOrderStateName(int $idState): string
    {
        $state = new OrderState($idState, $this->id_lang);
        return $state->name ?? '';
    }
}
