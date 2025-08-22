<?php
if (!defined('ABSPATH')) exit;
?>

<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <form method="post" action="options.php">
        <?php settings_fields('ets_settings'); ?>
        <?php do_settings_sections('ets_settings'); ?>
        
        <div class="ets-settings-container">
            <div class="ets-settings-section">
                <h2>Ecwid API Configuration</h2>
                <p>Configure your Ecwid store connection to fetch products.</p>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="ets_ecwid_store_id">Store ID</label>
                        </th>
                        <td>
                            <input type="text" id="ets_ecwid_store_id" name="ets_ecwid_store_id" 
                                   value="<?php echo esc_attr(get_option('ets_ecwid_store_id')); ?>" 
                                   class="regular-text" required />
                            <p class="description">
                                Your Ecwid store ID. Found in your Ecwid admin under Settings → General.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ets_ecwid_api_token">API Token</label>
                        </th>
                        <td>
                            <input type="password" id="ets_ecwid_api_token" name="ets_ecwid_api_token" 
                                   value="<?php echo esc_attr(get_option('ets_ecwid_api_token')); ?>" 
                                   class="regular-text" required />
                            <p class="description">
                                Your Ecwid API token with read access to products. 
                                <a href="https://developers.ecwid.com/api-documentation/token" target="_blank">
                                    How to get API token
                                </a>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
            
            <div class="ets-settings-section">
                <h2>Stripe API Configuration</h2>
                <p>Configure your Stripe account for subscription management.</p>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="ets_stripe_secret_key">Secret Key</label>
                        </th>
                        <td>
                            <input type="password" id="ets_stripe_secret_key" name="ets_stripe_secret_key" 
                                   value="<?php echo esc_attr(get_option('ets_stripe_secret_key')); ?>" 
                                   class="regular-text" required />
                            <p class="description">
                                Your Stripe secret key (starts with sk_). 
                                <strong>Use test keys for development.</strong>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ets_stripe_publishable_key">Publishable Key</label>
                        </th>
                        <td>
                            <input type="text" id="ets_stripe_publishable_key" name="ets_stripe_publishable_key" 
                                   value="<?php echo esc_attr(get_option('ets_stripe_publishable_key')); ?>" 
                                   class="regular-text" required />
                            <p class="description">
                                Your Stripe publishable key (starts with pk_).
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ets_stripe_webhook_secret">Webhook Secret</label>
                        </th>
                        <td>
                            <input type="password" id="ets_stripe_webhook_secret" name="ets_stripe_webhook_secret" 
                                   value="<?php echo esc_attr(get_option('ets_stripe_webhook_secret')); ?>" 
                                   class="regular-text" />
                            <p class="description">
                                Webhook endpoint secret for secure webhook handling. 
                                Set up webhook endpoint: <code><?php echo site_url('/wp-json/ets/v1/webhook/stripe'); ?></code>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
            
            <div class="ets-settings-section">
                <h2>Default Subscription Settings</h2>
                <p>Default values for new subscriptions.</p>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="ets_default_subscription_interval">Default Interval</label>
                        </th>
                        <td>
                            <select id="ets_default_subscription_interval" name="ets_default_subscription_interval">
                                <option value="day" <?php selected(get_option('ets_default_subscription_interval'), 'day'); ?>>
                                    Daily
                                </option>
                                <option value="week" <?php selected(get_option('ets_default_subscription_interval'), 'week'); ?>>
                                    Weekly
                                </option>
                                <option value="month" <?php selected(get_option('ets_default_subscription_interval'), 'month'); ?>>
                                    Monthly
                                </option>
                                <option value="year" <?php selected(get_option('ets_default_subscription_interval'), 'year'); ?>>
                                    Yearly
                                </option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ets_default_subscription_interval_count">Default Interval Count</label>
                        </th>
                        <td>
                            <input type="number" id="ets_default_subscription_interval_count" 
                                   name="ets_default_subscription_interval_count" 
                                   value="<?php echo esc_attr(get_option('ets_default_subscription_interval_count', 1)); ?>" 
                                   min="1" max="365" />
                            <p class="description">
                                Default number of intervals between charges (e.g., 2 = every 2 months).
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        
        <?php submit_button(); ?>
    </form>
    
    <div class="ets-settings-section">
        <h2>Test API Connections</h2>
        <p>Test your API configurations to ensure they're working correctly.</p>
        
        <div class="ets-test-controls">
            <button type="button" class="button" id="ets-test-ecwid">
                Test Ecwid Connection
            </button>
            <button type="button" class="button" id="ets-test-stripe">
                Test Stripe Connection
            </button>
            <span class="spinner" id="ets-test-loading"></span>
        </div>
        
        <div id="ets-test-results" style="margin-top: 15px;"></div>
    </div>
</div>

<style>
.ets-settings-container {
    max-width: 800px;
}

.ets-settings-section {
    background: #fff;
    border: 1px solid #ccd0d4;
    padding: 20px;
    margin-bottom: 20px;
    border-radius: 3px;
}

.ets-settings-section h2 {
    margin-top: 0;
}

.ets-test-controls {
    display: flex;
    align-items: center;
    gap: 10px;
}

.ets-test-result {
    padding: 10px;
    border-radius: 3px;
    margin-top: 10px;
}

.ets-test-success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.ets-test-error {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}
</style>

<script>
jQuery(document).ready(function($) {
    $('#ets-test-ecwid').on('click', function() {
        testApiConnection('ecwid');
    });
    
    $('#ets-test-stripe').on('click', function() {
        testApiConnection('stripe');
    });
    
    function testApiConnection(type) {
        $('#ets-test-loading').addClass('is-active');
        $('#ets-test-results').empty();
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'ets_test_api',
                type: type,
                nonce: '<?php echo wp_create_nonce('ets_admin_nonce'); ?>'
            },
            success: function(response) {
                $('#ets-test-loading').removeClass('is-active');
                
                var resultClass = response.success ? 'ets-test-success' : 'ets-test-error';
                var message = response.success ? response.data : response.data;
                
                $('#ets-test-results').html(
                    '<div class="ets-test-result ' + resultClass + '">' + 
                    '<strong>' + type.charAt(0).toUpperCase() + type.slice(1) + ' Test:</strong> ' + 
                    message + 
                    '</div>'
                );
            },
            error: function() {
                $('#ets-test-loading').removeClass('is-active');
                $('#ets-test-results').html(
                    '<div class="ets-test-result ets-test-error">' +
                    '<strong>Error:</strong> Failed to test API connection.' +
                    '</div>'
                );
            }
        });
    }
});
</script>