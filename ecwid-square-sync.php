<?php
/*
Plugin Name: Ecwid to Stripe Subscriptions
Description: Pull specific products from Ecwid and convert them into recurring subscriptions through Stripe. Seamlessly transform one-time purchases into subscription services.
Version: 2.0.0
Author: Your Name
License: GPLv2 or later
*/

if (!defined('ABSPATH')) exit;

if (!defined('ETS_VERSION')) define('ETS_VERSION', '2.0.0');
if (!defined('ETS_PLUGIN_TITLE')) define('ETS_PLUGIN_TITLE', 'Ecwid to Stripe Subscriptions');
if (!defined('ETS_PLUGIN_DIR')) define('ETS_PLUGIN_DIR', plugin_dir_path(__FILE__));
if (!defined('ETS_PLUGIN_URL')) define('ETS_PLUGIN_URL', plugin_dir_url(__FILE__));

/*
CHANGELOG
2.0.0 (Initial Ecwid to Stripe Subscription Integration)
- Complete rewrite from Square sync to Stripe subscriptions
- Added Ecwid API integration for product fetching
- Added Stripe API integration for subscription creation
- Created admin interface for product selection and subscription management
- Added webhook handlers for Stripe subscription events
- Added configuration settings for API keys and subscription options
*/

/**
 * Main plugin class for Ecwid to Stripe Subscriptions
 */
class EcwidStripeSubscriptions {
    
    private $ecwid_api;
    private $stripe_api;
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'admin_init'));
        add_action('wp_ajax_ets_fetch_products', array($this, 'ajax_fetch_products'));
        add_action('wp_ajax_ets_create_subscription', array($this, 'ajax_create_subscription'));
        add_action('wp_ajax_ets_deactivate_subscription', array($this, 'ajax_deactivate_subscription'));
        add_action('wp_ajax_ets_test_api', array($this, 'ajax_test_api'));
        add_action('wp_ajax_ets_get_subscription_products', array($this, 'ajax_get_subscription_products'));
        add_action('wp_ajax_nopriv_ets_get_subscription_products', array($this, 'ajax_get_subscription_products'));
        add_action('wp_ajax_ets_create_checkout_session', array($this, 'ajax_create_checkout_session'));
        add_action('wp_ajax_nopriv_ets_create_checkout_session', array($this, 'ajax_create_checkout_session'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    /**
     * Initialize the plugin
     */
    public function init() {
        $this->load_dependencies();
        $this->init_apis();
        $this->setup_hooks();
    }
    
    /**
     * Load required dependencies
     */
    private function load_dependencies() {
        // Load Stripe PHP library if not already loaded
        if (!class_exists('Stripe_Product')) {
            require_once(ETS_PLUGIN_DIR . 'includes/stripe-php/init.php');
        }
    }
    
    /**
     * Initialize API connections
     */
    private function init_apis() {
        $this->ecwid_api = new ETS_Ecwid_API();
        $this->stripe_api = new ETS_Stripe_API();
    }
    
    /**
     * Setup WordPress hooks
     */
    private function setup_hooks() {
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        $this->create_tables();
        $this->set_default_options();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clean up scheduled events
        wp_clear_scheduled_hook('ets_sync_subscriptions');
    }
    
    /**
     * Create database tables
     */
    private function create_tables() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'ets_product_subscriptions';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            ecwid_product_id bigint(20) NOT NULL,
            stripe_product_id varchar(255) NOT NULL,
            stripe_price_id varchar(255) NOT NULL,
            subscription_interval varchar(20) NOT NULL,
            subscription_interval_count int(11) NOT NULL DEFAULT 1,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ecwid_product_id (ecwid_product_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Set default plugin options
     */
    private function set_default_options() {
        add_option('ets_ecwid_store_id', '');
        add_option('ets_ecwid_api_token', '');
        add_option('ets_stripe_secret_key', '');
        add_option('ets_stripe_publishable_key', '');
        add_option('ets_stripe_webhook_secret', '');
        add_option('ets_default_subscription_interval', 'month');
        add_option('ets_default_subscription_interval_count', 1);
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            'Ecwid to Stripe Subscriptions',
            'ETS Subscriptions',
            'manage_options',
            'ets-subscriptions',
            array($this, 'admin_page'),
            'dashicons-update',
            30
        );
        
        add_submenu_page(
            'ets-subscriptions',
            'Settings',
            'Settings',
            'manage_options',
            'ets-settings',
            array($this, 'settings_page')
        );
    }
    
    /**
     * Initialize admin settings
     */
    public function admin_init() {
        register_setting('ets_settings', 'ets_ecwid_store_id');
        register_setting('ets_settings', 'ets_ecwid_api_token');
        register_setting('ets_settings', 'ets_stripe_secret_key');
        register_setting('ets_settings', 'ets_stripe_publishable_key');
        register_setting('ets_settings', 'ets_stripe_webhook_secret');
        register_setting('ets_settings', 'ets_default_subscription_interval');
        register_setting('ets_settings', 'ets_default_subscription_interval_count');
    }
    
    /**
     * Main admin page
     */
    public function admin_page() {
        include(ETS_PLUGIN_DIR . 'includes/admin/admin-page.php');
    }
    
    /**
     * Settings page
     */
    public function settings_page() {
        include(ETS_PLUGIN_DIR . 'includes/admin/settings-page.php');
    }
    
    /**
     * AJAX handler for fetching Ecwid products
     */
    public function ajax_fetch_products() {
        check_ajax_referer('ets_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $products = $this->ecwid_api->get_products();
        wp_send_json_success($products);
    }
    
    /**
     * AJAX handler for creating subscription
     */
    public function ajax_create_subscription() {
        check_ajax_referer('ets_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $product_id = intval($_POST['product_id']);
        $interval = sanitize_text_field($_POST['interval']);
        $interval_count = intval($_POST['interval_count']);
        
        $result = $this->create_subscription_for_product($product_id, $interval, $interval_count);
        
        if ($result) {
            wp_send_json_success('Subscription created successfully');
        } else {
            wp_send_json_error('Failed to create subscription');
        }
    }
    
    /**
     * AJAX handler for deactivating subscription
     */
    public function ajax_deactivate_subscription() {
        check_ajax_referer('ets_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $subscription_id = intval($_POST['id']);
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'ets_product_subscriptions';
        
        $result = $wpdb->update(
            $table_name,
            array('is_active' => 0),
            array('id' => $subscription_id)
        );
        
        if ($result !== false) {
            wp_send_json_success('Subscription deactivated successfully');
        } else {
            wp_send_json_error('Failed to deactivate subscription');
        }
    }
    
    /**
     * AJAX handler for testing API connections
     */
    public function ajax_test_api() {
        check_ajax_referer('ets_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $type = sanitize_text_field($_POST['type']);
        
        if ($type === 'ecwid') {
            $result = $this->test_ecwid_connection();
        } elseif ($type === 'stripe') {
            $result = $this->test_stripe_connection();
        } else {
            wp_send_json_error('Invalid API type');
            return;
        }
        
        if ($result['success']) {
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * AJAX handler for getting subscription products (frontend)
     */
    public function ajax_get_subscription_products() {
        check_ajax_referer('ets_frontend_nonce', 'nonce');
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'ets_product_subscriptions';
        
        $subscriptions = $wpdb->get_results("
            SELECT ecwid_product_id, stripe_price_id, subscription_interval, 
                   subscription_interval_count 
            FROM $table_name 
            WHERE is_active = 1
        ");
        
        wp_send_json_success($subscriptions);
    }
    
    /**
     * AJAX handler for creating Stripe checkout session
     */
    public function ajax_create_checkout_session() {
        check_ajax_referer('ets_frontend_nonce', 'nonce');
        
        $price_id = sanitize_text_field($_POST['price_id']);
        $product_name = sanitize_text_field($_POST['product_name']);
        
        $checkout_session = $this->stripe_api->create_checkout_session($price_id, $product_name);
        
        if ($checkout_session) {
            wp_send_json_success(array('checkout_url' => $checkout_session['url']));
        } else {
            wp_send_json_error('Failed to create checkout session');
        }
    }
    
    /**
     * Test Ecwid API connection
     */
    private function test_ecwid_connection() {
        $products = $this->ecwid_api->get_products(1); // Just get 1 product to test
        
        if (isset($products['error'])) {
            return array('success' => false, 'message' => $products['error']);
        }
        
        if (isset($products['items'])) {
            return array('success' => true, 'message' => 'Successfully connected to Ecwid API. Found ' . $products['total'] . ' products.');
        }
        
        return array('success' => false, 'message' => 'Unable to connect to Ecwid API.');
    }
    
    /**
     * Test Stripe API connection
     */
    private function test_stripe_connection() {
        $result = $this->stripe_api->test_connection();
        
        if ($result['success']) {
            return array('success' => true, 'message' => 'Successfully connected to Stripe API.');
        } else {
            return array('success' => false, 'message' => $result['message']);
        }
    }
    
    /**
     * Create subscription for Ecwid product
     */
    private function create_subscription_for_product($product_id, $interval, $interval_count) {
        $product = $this->ecwid_api->get_product($product_id);
        if (!$product) {
            return false;
        }
        
        $stripe_product = $this->stripe_api->create_product($product);
        if (!$stripe_product) {
            return false;
        }
        
        $stripe_price = $this->stripe_api->create_price($stripe_product['id'], $product['price'], $interval, $interval_count);
        if (!$stripe_price) {
            return false;
        }
        
        // Save to database
        global $wpdb;
        $table_name = $wpdb->prefix . 'ets_product_subscriptions';
        
        return $wpdb->insert(
            $table_name,
            array(
                'ecwid_product_id' => $product_id,
                'stripe_product_id' => $stripe_product['id'],
                'stripe_price_id' => $stripe_price['id'],
                'subscription_interval' => $interval,
                'subscription_interval_count' => $interval_count
            )
        );
    }
    
    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        register_rest_route('ets/v1', '/webhook/stripe', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_stripe_webhook'),
            'permission_callback' => '__return_true'
        ));
    }
    
    /**
     * Handle Stripe webhooks
     */
    public function handle_stripe_webhook($request) {
        $payload = $request->get_body();
        $sig_header = $request->get_header('stripe-signature');
        $endpoint_secret = get_option('ets_stripe_webhook_secret');
        
        try {
            $event = Stripe_Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
        } catch (Exception $e) {
            return new WP_Error('webhook_error', 'Webhook signature verification failed', array('status' => 400));
        }
        
        // Handle the event
        if (isset($event['type'])) {
            switch ($event['type']) {
                case 'customer.subscription.created':
                    $this->handle_subscription_created($event['data']['object']);
                    break;
                case 'customer.subscription.updated':
                    $this->handle_subscription_updated($event['data']['object']);
                    break;
                case 'customer.subscription.deleted':
                    $this->handle_subscription_deleted($event['data']['object']);
                    break;
                default:
                    // Unknown event type
                    break;
            }
        }
        
        return array('status' => 'success');
    }
    
    /**
     * Handle subscription created webhook
     */
    private function handle_subscription_created($subscription) {
        // Log subscription creation
        error_log('ETS: Subscription created - ' . $subscription['id']);
    }
    
    /**
     * Handle subscription updated webhook
     */
    private function handle_subscription_updated($subscription) {
        // Log subscription update
        error_log('ETS: Subscription updated - ' . $subscription['id']);
    }
    
    /**
     * Handle subscription deleted webhook
     */
    private function handle_subscription_deleted($subscription) {
        // Log subscription deletion
        error_log('ETS: Subscription deleted - ' . $subscription['id']);
    }
    
    /**
     * Enqueue frontend scripts
     */
    public function enqueue_scripts() {
        if (is_admin()) return;
        
        wp_enqueue_script('ets-frontend', ETS_PLUGIN_URL . 'assets/js/frontend.js', array('jquery'), ETS_VERSION, true);
        wp_localize_script('ets-frontend', 'ets_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ets_frontend_nonce')
        ));
    }
    
    /**
     * Enqueue admin scripts
     */
    public function admin_enqueue_scripts($hook) {
        if (strpos($hook, 'ets-') === false) return;
        
        wp_enqueue_script('ets-admin', ETS_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), ETS_VERSION, true);
        wp_enqueue_style('ets-admin', ETS_PLUGIN_URL . 'assets/css/admin.css', array(), ETS_VERSION);
        
        wp_localize_script('ets-admin', 'ets_admin_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ets_admin_nonce')
        ));
    }
}

/**
 * Ecwid API wrapper class
 */
class ETS_Ecwid_API {
    
    private $store_id;
    private $api_token;
    private $api_url;
    
    public function __construct() {
        $this->store_id = get_option('ets_ecwid_store_id');
        $this->api_token = get_option('ets_ecwid_api_token');
        $this->api_url = 'https://app.ecwid.com/api/v3/';
    }
    
    /**
     * Get products from Ecwid
     */
    public function get_products($limit = 100, $offset = 0) {
        if (empty($this->store_id) || empty($this->api_token)) {
            return array('error' => 'API credentials not configured');
        }
        
        $url = $this->api_url . $this->store_id . '/products';
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_token,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'limit' => $limit,
                'offset' => $offset
            ))
        );
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            return array('error' => $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        return $data;
    }
    
    /**
     * Get single product from Ecwid
     */
    public function get_product($product_id) {
        if (empty($this->store_id) || empty($this->api_token)) {
            return false;
        }
        
        $url = $this->api_url . $this->store_id . '/products/' . $product_id;
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_token,
                'Content-Type' => 'application/json'
            )
        );
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        return $data;
    }
}

/**
 * Stripe API wrapper class
 */
class ETS_Stripe_API {
    
    private $secret_key;
    
    public function __construct() {
        $this->secret_key = get_option('ets_stripe_secret_key');
        if (!empty($this->secret_key)) {
            StripeAPI::setApiKey($this->secret_key);
        }
    }
    
    /**
     * Create product in Stripe
     */
    public function create_product($ecwid_product) {
        if (empty($this->secret_key)) {
            return false;
        }
        
        try {
            $product = Stripe_Product::create([
                'name' => $ecwid_product['name'],
                'description' => isset($ecwid_product['description']) ? $ecwid_product['description'] : '',
                'metadata' => [
                    'ecwid_product_id' => $ecwid_product['id'],
                    'source' => 'ecwid'
                ]
            ]);
            
            return $product;
        } catch (Exception $e) {
            error_log('ETS Stripe Product Creation Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create price in Stripe
     */
    public function create_price($product_id, $amount, $interval, $interval_count) {
        if (empty($this->secret_key)) {
            return false;
        }
        
        try {
            $price = Stripe_Price::create([
                'product' => $product_id,
                'unit_amount' => round($amount * 100), // Convert to cents
                'currency' => 'usd',
                'recurring' => [
                    'interval' => $interval,
                    'interval_count' => $interval_count
                ]
            ]);
            
            return $price;
        } catch (Exception $e) {
            error_log('ETS Stripe Price Creation Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create subscription
     */
    public function create_subscription($customer_id, $price_id) {
        if (empty($this->secret_key)) {
            return false;
        }
        
        try {
            $subscription = Stripe_Subscription::create([
                'customer' => $customer_id,
                'items' => [['price' => $price_id]],
                'payment_behavior' => 'default_incomplete',
                'expand' => ['latest_invoice.payment_intent']
            ]);
            
            return $subscription;
        } catch (Exception $e) {
            error_log('ETS Stripe Subscription Creation Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create checkout session for subscription
     */
    public function create_checkout_session($price_id, $product_name) {
        if (empty($this->secret_key)) {
            return false;
        }
        
        try {
            $session = Stripe_Checkout_Session::create([
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price' => $price_id,
                    'quantity' => 1,
                ]],
                'mode' => 'subscription',
                'success_url' => site_url('?ets_subscription=success'),
                'cancel_url' => site_url('?ets_subscription=cancelled'),
                'metadata' => [
                    'product_name' => $product_name,
                    'source' => 'ets_plugin'
                ]
            ]);
            
            return $session;
        } catch (Exception $e) {
            error_log('ETS Stripe Checkout Session Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Test Stripe API connection
     */
    public function test_connection() {
        if (empty($this->secret_key)) {
            return array('success' => false, 'message' => 'Secret key not configured');
        }
        
        try {
            // Try to retrieve account information
            $account = Stripe_Account::retrieve();
            
            if ($account && isset($account['id'])) {
                return array('success' => true, 'message' => 'Connected to Stripe account: ' . $account['id']);
            } else {
                return array('success' => false, 'message' => 'Unable to retrieve account information');
            }
        } catch (Exception $e) {
            return array('success' => false, 'message' => $e->getMessage());
        }
    }
}

// Initialize the plugin
new EcwidStripeSubscriptions();
