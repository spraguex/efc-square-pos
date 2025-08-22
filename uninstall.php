<?php
/**
 * Uninstall script for EFC Square POS
 * 
 * This file runs when the plugin is uninstalled (deleted)
 */

// If uninstall not called from WordPress, then exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete plugin options
delete_option('efc_square_pos_app_id');
delete_option('efc_square_pos_access_token');
delete_option('efc_square_pos_environment');

// Drop custom tables
global $wpdb;
$table_name = $wpdb->prefix . 'efc_square_pos_transactions';
$wpdb->query("DROP TABLE IF EXISTS $table_name");

// Clear any cached data
wp_cache_flush();