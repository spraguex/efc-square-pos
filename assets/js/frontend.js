// Frontend JavaScript for Ecwid to Stripe Subscriptions

(function($) {
    'use strict';
    
    // Initialize when document is ready
    $(document).ready(function() {
        initEtsSubscriptions();
    });
    
    function initEtsSubscriptions() {
        // Add subscription buttons to Ecwid products if needed
        enhanceEcwidProducts();
        
        // Handle subscription purchase clicks
        $(document).on('click', '.ets-subscribe-button', handleSubscriptionClick);
    }
    
    function enhanceEcwidProducts() {
        // This function could be used to add subscription options
        // to existing Ecwid product displays on the frontend
        
        // Check if we're on a page with Ecwid products
        if ($('.ecwid').length > 0) {
            // Wait for Ecwid to load, then enhance products
            setTimeout(function() {
                addSubscriptionOptions();
            }, 2000);
        }
    }
    
    function addSubscriptionOptions() {
        // Look for products that have subscription equivalents
        // This would integrate with the WordPress database to check
        // which Ecwid products have been converted to subscriptions
        
        $.ajax({
            url: ets_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ets_get_subscription_products',
                nonce: ets_ajax.nonce
            },
            success: function(response) {
                if (response.success && response.data) {
                    displaySubscriptionOptions(response.data);
                }
            }
        });
    }
    
    function displaySubscriptionOptions(subscriptionProducts) {
        // Add subscription buttons next to regular purchase buttons
        subscriptionProducts.forEach(function(product) {
            var productSelector = '[data-product-id="' + product.ecwid_product_id + '"]';
            var $productElement = $(productSelector);
            
            if ($productElement.length > 0) {
                var subscriptionButton = createSubscriptionButton(product);
                $productElement.find('.ecwid-productBrowser-price').after(subscriptionButton);
            }
        });
    }
    
    function createSubscriptionButton(product) {
        var intervalText = getIntervalText(product.subscription_interval, product.subscription_interval_count);
        
        var buttonHtml = '<div class="ets-subscription-option">';
        buttonHtml += '<p class="ets-subscription-text">Or subscribe and save:</p>';
        buttonHtml += '<button type="button" class="ets-subscribe-button" ';
        buttonHtml += 'data-price-id="' + product.stripe_price_id + '" ';
        buttonHtml += 'data-product-name="' + product.product_name + '">';
        buttonHtml += 'Subscribe ' + intervalText;
        buttonHtml += '</button>';
        buttonHtml += '</div>';
        
        return buttonHtml;
    }
    
    function getIntervalText(interval, count) {
        var text = '';
        
        if (count > 1) {
            text = 'every ' + count + ' ';
        } else {
            text = interval === 'month' ? 'monthly' : 
                   interval === 'year' ? 'yearly' :
                   interval === 'week' ? 'weekly' :
                   interval === 'day' ? 'daily' :
                   'every ' + interval;
        }
        
        if (count > 1) {
            text += interval + 's';
        }
        
        return text;
    }
    
    function handleSubscriptionClick(e) {
        e.preventDefault();
        
        var $button = $(this);
        var priceId = $button.data('price-id');
        var productName = $button.data('product-name');
        
        // Disable button and show loading
        $button.prop('disabled', true).text('Loading...');
        
        // Create Stripe checkout session
        createCheckoutSession(priceId, productName, $button);
    }
    
    function createCheckoutSession(priceId, productName, $button) {
        $.ajax({
            url: ets_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ets_create_checkout_session',
                price_id: priceId,
                product_name: productName,
                nonce: ets_ajax.nonce
            },
            success: function(response) {
                if (response.success && response.data.checkout_url) {
                    // Redirect to Stripe checkout
                    window.location.href = response.data.checkout_url;
                } else {
                    showError('Failed to create checkout session: ' + (response.data || 'Unknown error'));
                    resetButton($button);
                }
            },
            error: function(xhr, status, error) {
                showError('Error creating checkout session: ' + error);
                resetButton($button);
            }
        });
    }
    
    function resetButton($button) {
        var originalText = $button.data('original-text') || 'Subscribe';
        $button.prop('disabled', false).text(originalText);
    }
    
    function showError(message) {
        // Create a simple error notification
        var $notification = $('<div class="ets-notification ets-error">' + message + '</div>');
        
        $('body').prepend($notification);
        
        // Auto-remove after 5 seconds
        setTimeout(function() {
            $notification.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
        
        // Allow manual dismissal
        $notification.on('click', function() {
            $(this).fadeOut(function() {
                $(this).remove();
            });
        });
    }
    
    function showSuccess(message) {
        var $notification = $('<div class="ets-notification ets-success">' + message + '</div>');
        
        $('body').prepend($notification);
        
        setTimeout(function() {
            $notification.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
        
        $notification.on('click', function() {
            $(this).fadeOut(function() {
                $(this).remove();
            });
        });
    }
    
    // Handle successful subscription (when returning from Stripe)
    function handleSubscriptionSuccess() {
        var urlParams = new URLSearchParams(window.location.search);
        
        if (urlParams.get('ets_subscription') === 'success') {
            showSuccess('Subscription created successfully! You will receive a confirmation email shortly.');
            
            // Clean up URL
            if (history.replaceState) {
                var newUrl = window.location.href.replace(/[?&]ets_subscription=success/, '');
                history.replaceState({}, document.title, newUrl);
            }
        }
        
        if (urlParams.get('ets_subscription') === 'cancelled') {
            showError('Subscription was cancelled. You can try again anytime.');
            
            if (history.replaceState) {
                var newUrl = window.location.href.replace(/[?&]ets_subscription=cancelled/, '');
                history.replaceState({}, document.title, newUrl);
            }
        }
    }
    
    // Check for subscription status on page load
    $(window).on('load', function() {
        handleSubscriptionSuccess();
    });
    
})(jQuery);

// CSS for notifications (injected via JavaScript)
(function() {
    var css = `
        .ets-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 4px;
            color: #fff;
            font-weight: 600;
            z-index: 10000;
            cursor: pointer;
            max-width: 400px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            animation: etsSlideIn 0.3s ease;
        }
        
        .ets-notification.ets-error {
            background: #dc3545;
        }
        
        .ets-notification.ets-success {
            background: #28a745;
        }
        
        .ets-subscription-option {
            margin: 15px 0;
            padding: 15px;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
        }
        
        .ets-subscription-text {
            margin: 0 0 10px 0;
            font-weight: 600;
            color: #495057;
        }
        
        .ets-subscribe-button {
            background: #007cba;
            color: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s ease;
        }
        
        .ets-subscribe-button:hover {
            background: #005a87;
        }
        
        .ets-subscribe-button:disabled {
            background: #6c757d;
            cursor: not-allowed;
        }
        
        @keyframes etsSlideIn {
            from {
                opacity: 0;
                transform: translateX(100px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        @media (max-width: 768px) {
            .ets-notification {
                top: 10px;
                right: 10px;
                left: 10px;
                right: 10px;
                max-width: none;
            }
        }
    `;
    
    var style = document.createElement('style');
    style.textContent = css;
    document.head.appendChild(style);
})();