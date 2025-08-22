<?php
if (!defined('ABSPATH')) exit;

// Get existing subscriptions
global $wpdb;
$table_name = $wpdb->prefix . 'ets_product_subscriptions';
$subscriptions = $wpdb->get_results("SELECT * FROM $table_name WHERE is_active = 1");
?>

<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <div class="ets-admin-container">
        <div class="ets-section">
            <h2>Available Ecwid Products</h2>
            <p>Select products from your Ecwid store to convert into Stripe subscriptions.</p>
            
            <div class="ets-controls">
                <button type="button" class="button button-primary" id="ets-fetch-products">
                    Fetch Products from Ecwid
                </button>
                <span class="spinner" id="ets-loading"></span>
            </div>
            
            <div id="ets-products-container" style="margin-top: 20px;">
                <!-- Products will be loaded here via AJAX -->
            </div>
        </div>
        
        <div class="ets-section">
            <h2>Active Subscriptions</h2>
            <p>Products that have been converted to Stripe subscriptions.</p>
            
            <?php if (empty($subscriptions)): ?>
                <p><em>No active subscriptions yet. Create your first subscription above.</em></p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Ecwid Product ID</th>
                            <th>Stripe Product ID</th>
                            <th>Subscription Interval</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subscriptions as $subscription): ?>
                            <tr>
                                <td><?php echo esc_html($subscription->ecwid_product_id); ?></td>
                                <td>
                                    <code><?php echo esc_html($subscription->stripe_product_id); ?></code>
                                </td>
                                <td>
                                    Every <?php echo esc_html($subscription->subscription_interval_count); ?> 
                                    <?php echo esc_html($subscription->subscription_interval); ?>(s)
                                </td>
                                <td>
                                    <span class="ets-status ets-status-active">Active</span>
                                </td>
                                <td><?php echo esc_html(date('M j, Y', strtotime($subscription->created_at))); ?></td>
                                <td>
                                    <a href="#" class="button button-small ets-view-stripe" 
                                       data-product-id="<?php echo esc_attr($subscription->stripe_product_id); ?>">
                                        View in Stripe
                                    </a>
                                    <a href="#" class="button button-small button-link-delete ets-deactivate" 
                                       data-id="<?php echo esc_attr($subscription->id); ?>">
                                        Deactivate
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Product Selection Modal -->
<div id="ets-product-modal" class="ets-modal" style="display: none;">
    <div class="ets-modal-content">
        <div class="ets-modal-header">
            <h3>Convert Product to Subscription</h3>
            <span class="ets-modal-close">&times;</span>
        </div>
        <div class="ets-modal-body">
            <form id="ets-subscription-form">
                <input type="hidden" id="ets-product-id" name="product_id" />
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="ets-interval">Billing Interval</label>
                        </th>
                        <td>
                            <select id="ets-interval" name="interval" required>
                                <option value="day">Daily</option>
                                <option value="week">Weekly</option>
                                <option value="month" selected>Monthly</option>
                                <option value="year">Yearly</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ets-interval-count">Interval Count</label>
                        </th>
                        <td>
                            <input type="number" id="ets-interval-count" name="interval_count" 
                                   value="1" min="1" max="365" required />
                            <p class="description">
                                Charge every X intervals (e.g., 2 = every 2 months)
                            </p>
                        </td>
                    </tr>
                </table>
                
                <div class="ets-modal-actions">
                    <button type="submit" class="button button-primary">
                        Create Subscription
                    </button>
                    <button type="button" class="button ets-modal-cancel">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.ets-admin-container {
    max-width: 1200px;
}

.ets-section {
    background: #fff;
    border: 1px solid #ccd0d4;
    padding: 20px;
    margin-bottom: 20px;
    border-radius: 3px;
}

.ets-controls {
    display: flex;
    align-items: center;
    gap: 10px;
}

.ets-product-card {
    border: 1px solid #ddd;
    padding: 15px;
    margin-bottom: 15px;
    border-radius: 3px;
    background: #f9f9f9;
}

.ets-product-header {
    display: flex;
    justify-content: between;
    align-items: flex-start;
    margin-bottom: 10px;
}

.ets-product-title {
    font-weight: bold;
    font-size: 16px;
    margin: 0 0 5px 0;
}

.ets-product-price {
    color: #0073aa;
    font-weight: bold;
    font-size: 18px;
}

.ets-product-description {
    color: #666;
    margin-bottom: 10px;
}

.ets-status {
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: bold;
}

.ets-status-active {
    background: #d4edda;
    color: #155724;
}

.ets-modal {
    position: fixed;
    z-index: 100000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
}

.ets-modal-content {
    background-color: #fefefe;
    margin: 5% auto;
    border: 1px solid #888;
    width: 80%;
    max-width: 600px;
    border-radius: 3px;
}

.ets-modal-header {
    padding: 20px;
    border-bottom: 1px solid #ddd;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.ets-modal-header h3 {
    margin: 0;
}

.ets-modal-close {
    color: #aaa;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
}

.ets-modal-close:hover {
    color: #000;
}

.ets-modal-body {
    padding: 20px;
}

.ets-modal-actions {
    margin-top: 20px;
    text-align: right;
}

.ets-modal-actions .button {
    margin-left: 10px;
}
</style>