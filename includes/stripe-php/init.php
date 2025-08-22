<?php
/**
 * Stripe PHP Library Placeholder
 * 
 * This is a placeholder file for the Stripe PHP library.
 * In a real implementation, you would:
 * 
 * 1. Install via Composer: composer require stripe/stripe-php
 * 2. Or download the library from: https://github.com/stripe/stripe-php
 * 3. Include the autoloader or init.php file
 * 
 * For this demo, we'll create basic stubs for the Stripe classes
 * that would normally be provided by the official library.
 */

if (!class_exists('Stripe\Stripe')) {
    
    // Define Stripe namespace classes as simple stubs
    class StripeAPI {
        public static $api_key = '';
        
        public static function setApiKey($key) {
            self::$api_key = $key;
        }
    }
    
    // Create mock classes in global namespace for compatibility
    if (!class_exists('Stripe_Product')) {
        class Stripe_Product {
            public static function create($params) {
                return array(
                    'id' => 'prod_' . uniqid(),
                    'name' => $params['name'],
                    'description' => $params['description'],
                    'metadata' => $params['metadata']
                );
            }
        }
    }
    
    if (!class_exists('Stripe_Price')) {
        class Stripe_Price {
            public static function create($params) {
                return array(
                    'id' => 'price_' . uniqid(),
                    'product' => $params['product'],
                    'unit_amount' => $params['unit_amount'],
                    'currency' => $params['currency'],
                    'recurring' => $params['recurring']
                );
            }
        }
    }
    
    if (!class_exists('Stripe_Subscription')) {
        class Stripe_Subscription {
            public static function create($params) {
                return array(
                    'id' => 'sub_' . uniqid(),
                    'customer' => $params['customer'],
                    'items' => $params['items'],
                    'status' => 'active'
                );
            }
        }
    }
    
    if (!class_exists('Stripe_Account')) {
        class Stripe_Account {
            public static function retrieve() {
                return array(
                    'id' => 'acct_' . uniqid(),
                    'business_profile' => array(
                        'name' => 'Test Business'
                    )
                );
            }
        }
    }
    
    if (!class_exists('Stripe_Checkout_Session')) {
        class Stripe_Checkout_Session {
            public static function create($params) {
                return array(
                    'id' => 'cs_' . uniqid(),
                    'url' => 'https://checkout.stripe.com/pay/' . uniqid(),
                    'metadata' => isset($params['metadata']) ? $params['metadata'] : array()
                );
            }
        }
    }
    
    if (!class_exists('Stripe_Webhook')) {
        class Stripe_Webhook {
            public static function constructEvent($payload, $sigHeader, $secret) {
                $event = json_decode($payload, true);
                if (!$event) {
                    throw new Exception('Invalid payload');
                }
                return $event;
            }
        }
    }
}

/**
 * Note for production use:
 * 
 * Replace this entire file with the official Stripe PHP library:
 * 
 * 1. Run: composer require stripe/stripe-php
 * 2. Replace this file with: require_once 'vendor/autoload.php';
 * 
 * Or download and include manually:
 * 
 * 1. Download from: https://github.com/stripe/stripe-php/releases
 * 2. Extract to includes/stripe-php/
 * 3. Replace this file with: require_once ETS_PLUGIN_DIR . 'includes/stripe-php/init.php';
 */