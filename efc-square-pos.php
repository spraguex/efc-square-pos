<?php
/*
Plugin Name: EFC Square POS
Description: Point of Sale integration between Easy Farm Cart and Square payment processing system.
Version: 1.0.0
Author: spraguex
License: GPLv2 or later
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
if (!defined('EFC_SQUARE_POS_VERSION')) {
    define('EFC_SQUARE_POS_VERSION', '1.0.0');
}

if (!defined('EFC_SQUARE_POS_PLUGIN_FILE')) {
    define('EFC_SQUARE_POS_PLUGIN_FILE', __FILE__);
}

if (!defined('EFC_SQUARE_POS_PLUGIN_DIR')) {
    define('EFC_SQUARE_POS_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

if (!defined('EFC_SQUARE_POS_PLUGIN_URL')) {
    define('EFC_SQUARE_POS_PLUGIN_URL', plugin_dir_url(__FILE__));
}

/**
 * Main plugin class
 */
class EFC_Square_POS {
    
    /**
     * Single instance of the plugin
     */
    private static $instance = null;
    
    /**
     * Get single instance of the plugin
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Plugin activation/deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    /**
     * Initialize the plugin
     */
    public function init() {
        // Load text domain for translations
        load_plugin_textdomain('efc-square-pos', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        // Initialize plugin features
        $this->init_hooks();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Add your hooks here
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_options_page(
            __('EFC Square POS Settings', 'efc-square-pos'),
            __('EFC Square POS', 'efc-square-pos'),
            'manage_options',
            'efc-square-pos',
            array($this, 'admin_page')
        );
    }
    
    /**
     * Admin page content
     */
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('efc_square_pos_settings');
                do_settings_sections('efc_square_pos_settings');
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Square Application ID', 'efc-square-pos'); ?></th>
                        <td>
                            <input type="text" name="efc_square_pos_app_id" value="<?php echo esc_attr(get_option('efc_square_pos_app_id')); ?>" class="regular-text" />
                            <p class="description"><?php _e('Enter your Square Application ID', 'efc-square-pos'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Square Access Token', 'efc-square-pos'); ?></th>
                        <td>
                            <input type="password" name="efc_square_pos_access_token" value="<?php echo esc_attr(get_option('efc_square_pos_access_token')); ?>" class="regular-text" />
                            <p class="description"><?php _e('Enter your Square Access Token', 'efc-square-pos'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Environment', 'efc-square-pos'); ?></th>
                        <td>
                            <select name="efc_square_pos_environment">
                                <option value="sandbox" <?php selected(get_option('efc_square_pos_environment'), 'sandbox'); ?>><?php _e('Sandbox', 'efc-square-pos'); ?></option>
                                <option value="production" <?php selected(get_option('efc_square_pos_environment'), 'production'); ?>><?php _e('Production', 'efc-square-pos'); ?></option>
                            </select>
                            <p class="description"><?php _e('Select the Square environment', 'efc-square-pos'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Enqueue frontend scripts
     */
    public function enqueue_scripts() {
        // Enqueue frontend scripts and styles here
    }
    
    /**
     * Enqueue admin scripts
     */
    public function admin_enqueue_scripts($hook) {
        // Only load on our admin page
        if ($hook !== 'settings_page_efc-square-pos') {
            return;
        }
        
        // Enqueue admin scripts and styles here
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Set default options
        add_option('efc_square_pos_environment', 'sandbox');
        
        // Create necessary database tables if needed
        $this->create_tables();
        
        // Clear any cached data
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear any cached data
        flush_rewrite_rules();
    }
    
    /**
     * Create database tables
     */
    private function create_tables() {
        global $wpdb;
        
        // Example table creation - modify as needed
        $table_name = $wpdb->prefix . 'efc_square_pos_transactions';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            transaction_id varchar(100) NOT NULL,
            amount decimal(10,2) NOT NULL,
            currency varchar(3) NOT NULL DEFAULT 'USD',
            status varchar(20) NOT NULL,
            square_payment_id varchar(100),
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY transaction_id (transaction_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}

// Initialize the plugin
function efc_square_pos() {
    return EFC_Square_POS::get_instance();
}

// Start the plugin
efc_square_pos();
