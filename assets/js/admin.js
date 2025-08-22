jQuery(document).ready(function($) {
    
    // Initialize admin interface
    initAdminInterface();
    
    function initAdminInterface() {
        bindEvents();
        loadExistingData();
    }
    
    function bindEvents() {
        // Fetch products button
        $('#ets-fetch-products').on('click', fetchEcwidProducts);
        
        // Modal controls
        $('.ets-modal-close, .ets-modal-cancel').on('click', closeModal);
        $(window).on('click', function(e) {
            if ($(e.target).hasClass('ets-modal')) {
                closeModal();
            }
        });
        
        // Subscription form
        $('#ets-subscription-form').on('submit', createSubscription);
        
        // Dynamic product card clicks (delegated)
        $(document).on('click', '.ets-create-subscription', showSubscriptionModal);
        $(document).on('click', '.ets-view-stripe', viewInStripe);
        $(document).on('click', '.ets-deactivate', deactivateSubscription);
    }
    
    function loadExistingData() {
        // Auto-fetch products if API credentials are configured
        var hasCredentials = $('body').data('has-api-credentials');
        if (hasCredentials) {
            fetchEcwidProducts();
        }
    }
    
    function fetchEcwidProducts() {
        var $button = $('#ets-fetch-products');
        var $loading = $('#ets-loading');
        var $container = $('#ets-products-container');
        
        $button.prop('disabled', true);
        $loading.addClass('is-active');
        $container.html('<p>Loading products from Ecwid...</p>');
        
        $.ajax({
            url: ets_admin_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ets_fetch_products',
                nonce: ets_admin_ajax.nonce
            },
            success: function(response) {
                $loading.removeClass('is-active');
                $button.prop('disabled', false);
                
                if (response.success && response.data) {
                    displayProducts(response.data);
                } else {
                    showError('Failed to fetch products: ' + (response.data || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                $loading.removeClass('is-active');
                $button.prop('disabled', false);
                showError('AJAX error: ' + error);
            }
        });
    }
    
    function displayProducts(data) {
        var $container = $('#ets-products-container');
        
        if (!data.items || data.items.length === 0) {
            $container.html('<p><em>No products found in your Ecwid store.</em></p>');
            return;
        }
        
        var html = '<div class="ets-products-grid">';
        
        data.items.forEach(function(product) {
            var price = product.price ? '$' + parseFloat(product.price).toFixed(2) : 'Price not set';
            var description = product.description ? 
                product.description.substring(0, 150) + (product.description.length > 150 ? '...' : '') : 
                'No description available';
            
            // Remove HTML tags from description
            description = $('<div>').html(description).text();
            
            html += '<div class="ets-product-card" data-product-id="' + product.id + '">';
            html += '<div class="ets-product-header">';
            html += '<div>';
            html += '<h3 class="ets-product-title">' + escapeHtml(product.name) + '</h3>';
            html += '<div class="ets-product-price">' + price + '</div>';
            html += '</div>';
            html += '<div class="ets-product-actions">';
            html += '<button type="button" class="button button-primary ets-create-subscription" data-product-id="' + product.id + '" data-product-name="' + escapeHtml(product.name) + '" data-product-price="' + (product.price || 0) + '">';
            html += 'Create Subscription';
            html += '</button>';
            html += '</div>';
            html += '</div>';
            html += '<div class="ets-product-description">' + escapeHtml(description) + '</div>';
            html += '<div class="ets-product-meta">';
            html += '<small>SKU: ' + (product.sku || 'N/A') + ' | ID: ' + product.id + '</small>';
            html += '</div>';
            html += '</div>';
        });
        
        html += '</div>';
        $container.html(html);
    }
    
    function showSubscriptionModal(e) {
        e.preventDefault();
        
        var $button = $(this);
        var productId = $button.data('product-id');
        var productName = $button.data('product-name');
        var productPrice = $button.data('product-price');
        
        $('#ets-product-id').val(productId);
        $('#ets-product-modal .ets-modal-header h3').text('Convert "' + productName + '" to Subscription ($' + parseFloat(productPrice).toFixed(2) + ')');
        
        // Set default values
        $('#ets-interval').val('month');
        $('#ets-interval-count').val(1);
        
        $('#ets-product-modal').show();
    }
    
    function closeModal() {
        $('#ets-product-modal').hide();
        $('#ets-subscription-form')[0].reset();
    }
    
    function createSubscription(e) {
        e.preventDefault();
        
        var $form = $(this);
        var $submitBtn = $form.find('button[type="submit"]');
        var formData = $form.serialize();
        
        $submitBtn.prop('disabled', true).text('Creating...');
        
        $.ajax({
            url: ets_admin_ajax.ajax_url,
            type: 'POST',
            data: formData + '&action=ets_create_subscription&nonce=' + ets_admin_ajax.nonce,
            success: function(response) {
                $submitBtn.prop('disabled', false).text('Create Subscription');
                
                if (response.success) {
                    showSuccess('Subscription created successfully!');
                    closeModal();
                    
                    // Refresh the page to show updated subscription list
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    showError('Failed to create subscription: ' + (response.data || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                $submitBtn.prop('disabled', false).text('Create Subscription');
                showError('AJAX error: ' + error);
            }
        });
    }
    
    function viewInStripe(e) {
        e.preventDefault();
        
        var productId = $(this).data('product-id');
        var stripeUrl = 'https://dashboard.stripe.com/products/' + productId;
        
        // Check if we're in test mode
        var isTestMode = $('body').data('stripe-test-mode');
        if (isTestMode) {
            stripeUrl = 'https://dashboard.stripe.com/test/products/' + productId;
        }
        
        window.open(stripeUrl, '_blank');
    }
    
    function deactivateSubscription(e) {
        e.preventDefault();
        
        var subscriptionId = $(this).data('id');
        
        if (!confirm('Are you sure you want to deactivate this subscription? This will stop new customers from subscribing to this product.')) {
            return;
        }
        
        var $button = $(this);
        $button.prop('disabled', true).text('Deactivating...');
        
        $.ajax({
            url: ets_admin_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ets_deactivate_subscription',
                id: subscriptionId,
                nonce: ets_admin_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    showSuccess('Subscription deactivated successfully!');
                    $button.closest('tr').fadeOut();
                } else {
                    $button.prop('disabled', false).text('Deactivate');
                    showError('Failed to deactivate subscription: ' + (response.data || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                $button.prop('disabled', false).text('Deactivate');
                showError('AJAX error: ' + error);
            }
        });
    }
    
    function showSuccess(message) {
        showNotice(message, 'success');
    }
    
    function showError(message) {
        showNotice(message, 'error');
    }
    
    function showNotice(message, type) {
        var noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
        var $notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + message + '</p></div>');
        
        $('.wrap h1').after($notice);
        
        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            $notice.fadeOut();
        }, 5000);
        
        // Add dismiss functionality
        $notice.on('click', '.notice-dismiss', function() {
            $notice.fadeOut();
        });
    }
    
    function escapeHtml(text) {
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }
    
    // Interval count validation
    $('#ets-interval-count').on('input', function() {
        var value = parseInt($(this).val());
        var interval = $('#ets-interval').val();
        var maxValue = 365;
        
        // Set reasonable limits based on interval
        switch (interval) {
            case 'day':
                maxValue = 365;
                break;
            case 'week':
                maxValue = 52;
                break;
            case 'month':
                maxValue = 12;
                break;
            case 'year':
                maxValue = 5;
                break;
        }
        
        if (value > maxValue) {
            $(this).val(maxValue);
        }
    });
    
    // Update max value when interval changes
    $('#ets-interval').on('change', function() {
        $('#ets-interval-count').trigger('input');
    });
    
});