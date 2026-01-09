<?php
/**
 * API Cart Controller
 * GET/POST/PUT/DELETE /api/v1/cart
 */

require_once _PS_MODULE_DIR_ . 'ravenapi/classes/RavenApiBaseController.php';

class RavenapiCartModuleFrontController extends RavenApiBaseModuleFrontController
{
    public function initContent()
    {
        parent::initContent();

        switch ($this->getMethod()) {
            case 'GET':
                $this->getCartContents();
                break;
            case 'POST':
                $this->addToCart();
                break;
            case 'PUT':
            case 'PATCH':
                $this->updateCart();
                break;
            case 'DELETE':
                $this->removeFromCart();
                break;
            default:
                $this->sendError('Method not allowed', 405);
        }
    }

    /**
     * GET - Récupère le contenu du panier
     */
    protected function getCartContents()
    {
        $cart = $this->getCart();
        $this->sendResponse(['cart' => $this->formatCart($cart)]);
    }

    /**
     * POST - Ajoute un produit au panier
     */
    protected function addToCart()
    {
        $productId = (int) $this->getBodyParam('id_product');
        $quantity = max(1, (int) $this->getBodyParam('quantity', 1));
        $attributeId = (int) $this->getBodyParam('id_product_attribute', 0);

        if (!$productId) {
            $this->sendError('Product ID required', 400);
        }

        $product = new Product($productId, true, $this->id_lang);
        if (!Validate::isLoadedObject($product) || !$product->active || !$product->available_for_order) {
            $this->sendError('Product not available', 404);
        }

        // Vérifier les déclinaisons
        if ($product->hasAttributes() && !$attributeId) {
            // Prendre la première combinaison par défaut
            $combinations = $product->getAttributeCombinations($this->id_lang);
            if (!empty($combinations)) {
                $attributeId = (int) $combinations[0]['id_product_attribute'];
            }
        }

        // Vérifier le stock
        $stockAvailable = StockAvailable::getQuantityAvailableByProduct($productId, $attributeId);
        if (!Product::isAvailableWhenOutOfStock($product->out_of_stock) && $stockAvailable < $quantity) {
            $this->sendError('Insufficient stock. Available: ' . $stockAvailable, 400);
        }

        $cart = $this->getCart();
        
        // Ajouter au panier
        $result = $cart->updateQty($quantity, $productId, $attributeId, false, 'up');
        
        if ($result === true || $result > 0) {
            $cart->update();
            $this->sendResponse([
                'success' => true,
                'message' => 'Product added to cart',
                'cart' => $this->formatCart($cart),
            ]);
        } else {
            $this->sendError('Failed to add product to cart', 500);
        }
    }

    /**
     * PUT/PATCH - Met à jour la quantité d'un produit
     */
    protected function updateCart()
    {
        $productId = (int) $this->getBodyParam('id_product');
        $quantity = (int) $this->getBodyParam('quantity');
        $attributeId = (int) $this->getBodyParam('id_product_attribute', 0);

        if (!$productId) {
            $this->sendError('Product ID required', 400);
        }

        if ($quantity < 0) {
            $this->sendError('Quantity must be positive', 400);
        }

        $cart = $this->getCart();

        // Si quantité = 0, supprimer le produit
        if ($quantity === 0) {
            $cart->deleteProduct($productId, $attributeId);
        } else {
            // Récupérer la quantité actuelle
            $currentQty = $cart->getProductQuantity($productId, $attributeId);
            $currentQty = isset($currentQty['quantity']) ? (int) $currentQty['quantity'] : 0;
            
            $diff = $quantity - $currentQty;
            
            if ($diff !== 0) {
                // Vérifier le stock si on augmente
                if ($diff > 0) {
                    $stockAvailable = StockAvailable::getQuantityAvailableByProduct($productId, $attributeId);
                    $product = new Product($productId);
                    if (!Product::isAvailableWhenOutOfStock($product->out_of_stock) && $stockAvailable < $quantity) {
                        $this->sendError('Insufficient stock. Available: ' . $stockAvailable, 400);
                    }
                }
                
                $operator = $diff > 0 ? 'up' : 'down';
                $cart->updateQty(abs($diff), $productId, $attributeId, false, $operator);
            }
        }

        $cart->update();
        $this->sendResponse([
            'success' => true,
            'message' => 'Cart updated',
            'cart' => $this->formatCart($cart),
        ]);
    }

    /**
     * DELETE - Supprime un produit du panier
     */
    protected function removeFromCart()
    {
        $productId = (int) $this->getBodyParam('id_product');
        $attributeId = (int) $this->getBodyParam('id_product_attribute', 0);

        if (!$productId) {
            $this->sendError('Product ID required', 400);
        }

        $cart = $this->getCart();
        $cart->deleteProduct($productId, $attributeId);
        $cart->update();

        $this->sendResponse([
            'success' => true,
            'message' => 'Product removed from cart',
            'cart' => $this->formatCart($cart),
        ]);
    }
}
